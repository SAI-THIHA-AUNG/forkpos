<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CreditInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\PosCashMovement;
use App\Models\PosOpeningShift;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Uom;
use App\Models\User;
use App\Support\DocNaming;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Core POS invoice engine — the Laravel equivalent of POS Awesome's
 * invoice.py validation + totals calculation + submission pipeline.
 *
 * Totals model (ERPNext compatible):
 *  - price_list_rate / rate are kept in the price-list basis.
 *  - When tax is inclusive, `amount` (qty x rate) still contains the tax;
 *    the net contribution is amount - tax_amount.
 *  - net_total  = sum of net contributions (before tax)
 *  - tax_total  = sum of tax amounts
 *  - grand_total = net_total + tax_total (- coupon discount)
 */
class InvoiceService
{
    public function __construct(
        protected StockService $stock,
        protected CouponService $coupons,
    ) {}

    /**
     * Create and complete a POS invoice in one atomic call.
     *
     * @param  array  $data  validated request payload
     * @return array{invoice: Invoice, duplicate: bool}
     */
    public function create(array $data, User $cashier): array
    {
        // Offline idempotency — same payload submitted twice returns the first doc.
        if (! empty($data['offline_id'])) {
            $existing = Invoice::where('offline_id', $data['offline_id'])->first();
            if ($existing) {
                return ['invoice' => $existing->load(['items', 'payments', 'taxes', 'customer', 'cashier']), 'duplicate' => true];
            }
        }

        // Retry on rare invoice_no collisions (concurrent terminals) — SQLite 19 / MySQL 1062.
        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                return $this->createWithinTransaction($data, $cashier);
            } catch (QueryException $e) {
                if ($attempts >= 3 || ! in_array($e->errorInfo[1] ?? null, [19, 1062], true)) {
                    throw $e;
                }
            }
        }
    }

    protected function createWithinTransaction(array $data, User $cashier): array
    {
        return DB::transaction(function () use ($data, $cashier) {
            $setting = Setting::current();
            /** @var PosProfile $profile */
            $profile = PosProfile::where('disabled', false)->findOrFail($data['pos_profile_id']);
            $currency = $profile->effectiveCurrency($setting);
            if ($currency === '') {
                throw ValidationException::withMessages([
                    'pos_profile_id' => ["POS profile '{$profile->name}' has no currency. Sync it from ERPNext or set one."],
                ]);
            }

            // Shift validation (POS Awesome: every invoice belongs to an open shift).
            $shift = null;
            if ($profile->require_shift) {
                $shiftId = $data['shift_id']
                    ?? PosOpeningShift::where('user_id', $cashier->id)
                        ->where('pos_profile_id', $profile->id)
                        ->where('status', PosOpeningShift::STATUS_OPEN)
                        ->value('id');
                if (! $shiftId) {
                    throw ValidationException::withMessages([
                        'shift_id' => ['No open shift found for this POS profile. Open a shift first.'],
                    ]);
                }
                $shift = PosOpeningShift::findOrFail($shiftId);
                if (! $shift->isOpen()) {
                    throw ValidationException::withMessages([
                        'shift_id' => ['The selected shift is already closed.'],
                    ]);
                }
            }

            $customer = $this->resolveCustomer($data);

            $isReturn = (bool) ($data['is_return'] ?? false);
            $returnAgainst = null;
            if ($isReturn) {
                $returnAgainst = Invoice::where('status', '!=', Invoice::STATUS_CANCELLED)
                    ->findOrFail($data['return_against_id']);
            }

            // ---- Credit terms ----------------------------------------------------
            $isCredit = ! $isReturn && (bool) ($data['is_credit'] ?? false);
            $creditDays = null;
            $dueDate = null;
            $postingDate = Carbon::parse($data['posting_datetime'] ?? now())->startOfDay();
            if ($isCredit) {
                if ($customer->is_walk_in) {
                    throw ValidationException::withMessages([
                        'customer_id' => ['Choose a customer for a credit sale — a walk-in customer cannot owe money.'],
                    ]);
                }
                if (! empty($data['due_date'])) {
                    $dueDate = Carbon::parse($data['due_date'])->startOfDay();
                    if ($dueDate->lt($postingDate)) {
                        throw ValidationException::withMessages([
                            'due_date' => ['The due date cannot be before the sale date.'],
                        ]);
                    }
                } elseif (! empty($data['credit_days'])) {
                    $creditDays = (int) $data['credit_days'];
                    if (! in_array($creditDays, Invoice::CREDIT_TERM_DAYS, true)) {
                        throw ValidationException::withMessages([
                            'credit_days' => ['Credit terms must be '.implode(', ', Invoice::CREDIT_TERM_DAYS).' days, or pick a due date.'],
                        ]);
                    }
                    $dueDate = $postingDate->copy()->addDays($creditDays);
                } else {
                    throw ValidationException::withMessages([
                        'due_date' => ['Pick the credit terms (7, 14 or 30 days) or a due date.'],
                    ]);
                }
            }

            // ---- Invoice header -------------------------------------------------
            $precision = $setting->currency_precision;
            $invoice = new Invoice([
                'customer_id' => $customer->id,
                'pos_profile_id' => $profile->id,
                'shift_id' => $shift?->id,
                'user_id' => $cashier->id,
                'status' => Invoice::STATUS_DRAFT,
                'posting_datetime' => $data['posting_datetime'] ?? now(),
                'is_return' => $isReturn,
                'return_against_id' => $returnAgainst?->id,
                'currency' => $currency,
                'price_list' => $profile->selling_price_list,
                'coupon_code' => $data['coupon_code'] ?? null,
                'loyalty_points_spent' => (int) ($data['loyalty_points'] ?? 0),
                'offline_id' => $data['offline_id'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'sync_status' => 'pending',
            ]);
            $invoice->posting_date = $invoice->posting_datetime->toDateString();
            $invoice->van_code = $profile->ensureVanCode();
            $invoice->invoice_no = $this->nextInvoiceNumber($setting, $profile, $isReturn);
            $invoice->save();

            // ---- Items ----------------------------------------------------------
            $netTotal = 0.0;
            $taxTotal = 0.0;
            $discountTotal = 0.0;
            $totalQty = 0.0;
            $taxBreakdown = []; // tax_id => [tax, amount]
            $warehouseId = $data['warehouse_id'] ?? $profile->warehouse_id;

            // A return is priced from the ORIGINAL invoice (rate actually paid,
            // incl. line + invoice-level discounts) and can never exceed what
            // is still returnable.
            $returnable = $returnAgainst ? $this->returnableLines($returnAgainst) : [];
            $returnTotals = []; // item_id => qty requested in this return

            foreach ($data['items'] as $line) {
                /** @var Item $item */
                $item = Item::available()->findOrFail($line['item_id']);

                $uomName = $line['uom'] ?? ($item->salesUom?->uom_name ?? $item->stockUom?->uom_name ?? 'Unit');
                $conversion = $this->conversionFactor($item, $uomName);

                // The list price always comes from the synced price list — a
                // client-sent price_list_rate is ignored so a tampered payload
                // cannot invent one.
                $listRate = $item->effectiveRate($profile->selling_price_list, $customer->id);
                $baseRate = $listRate;

                $returnLine = null;
                if ($isReturn) {
                    $returnLine = $returnable[$item->id] ?? null;
                    if (! $returnLine) {
                        throw ValidationException::withMessages([
                            'items' => ["{$item->item_name} was not sold on invoice {$returnAgainst->invoice_no}."],
                        ]);
                    }
                    $returnTotals[$item->id] = ($returnTotals[$item->id] ?? 0.0) + abs((float) $line['qty']);
                    if ($returnTotals[$item->id] > $returnLine['remaining'] + 0.0001) {
                        throw ValidationException::withMessages([
                            'items' => [sprintf(
                                'Cannot return %s of %s: only %s left to return on %s.',
                                rtrim(rtrim(number_format($returnTotals[$item->id], 3, '.', ''), '0'), '.'),
                                $item->item_name,
                                rtrim(rtrim(number_format($returnLine['remaining'], 3, '.', ''), '0'), '.'),
                                $returnAgainst->invoice_no,
                            )],
                        ]);
                    }
                    $listRate = $returnLine['rate'];
                    $baseRate = $returnLine['rate'];
                    $line['uom'] = $returnLine['uom'];
                    $line['tax_id'] = $returnLine['tax_id'];
                    unset($line['rate'], $line['discount_percentage'], $line['discount_amount']);
                    $uomName = $returnLine['uom'];
                    $conversion = $returnLine['conversion_factor'];
                }

                // Rate overrides are gated by the profile (allow_rate_change).
                $requestedRate = isset($line['rate']) ? (float) $line['rate'] : null;
                if ($requestedRate !== null && abs($requestedRate - $listRate) > 0.0001) {
                    if (! $profile->allow_rate_change) {
                        throw ValidationException::withMessages([
                            'items' => ["Changing the price is disabled for POS profile '{$profile->name}'."],
                        ]);
                    }
                    $baseRate = $requestedRate;
                }

                $qty = (float) $line['qty'];
                if ($isReturn) {
                    $qty = -abs($qty);
                }

                $discountPercentage = (float) ($line['discount_percentage'] ?? 0);
                $discountAmount = (float) ($line['discount_amount'] ?? 0);
                $hasDiscount = $discountPercentage > 0 || $discountAmount > 0;

                if ($hasDiscount && ! $profile->allow_discount_change) {
                    throw ValidationException::withMessages([
                        'items' => ["Discounts are disabled for POS profile '{$profile->name}'."],
                    ]);
                }
                if ($hasDiscount && ! $item->allow_discount) {
                    throw ValidationException::withMessages([
                        'items' => ["Discount is not allowed on item {$item->item_code}."],
                    ]);
                }
                if ($discountPercentage > 0 && $profile->max_discount_percentage > 0
                    && $discountPercentage > $profile->max_discount_percentage) {
                    throw ValidationException::withMessages([
                        'items' => ["Discount {$discountPercentage}% exceeds the profile limit of {$profile->max_discount_percentage}%."],
                    ]);
                }
                if ($discountAmount > 0 && $profile->max_discount_percentage > 0) {
                    $lineValue = $baseRate * abs($qty);
                    $effectivePercentage = $lineValue > 0 ? ($discountAmount / $lineValue) * 100 : 0.0;
                    if ($effectivePercentage > $profile->max_discount_percentage + 0.0001) {
                        throw ValidationException::withMessages([
                            'items' => [sprintf('Discount %.2f%% exceeds the profile limit of %.2f%%.', $effectivePercentage, $profile->max_discount_percentage)],
                        ]);
                    }
                }

                $rate = $baseRate;
                if ($discountPercentage > 0) {
                    $rate = $baseRate * (1 - $discountPercentage / 100);
                }
                $lineDiscount = max(0.0, ($baseRate - $rate) * abs($qty)); // line-level discount total
                if ($discountAmount > 0) {
                    // `discount_amount` is a line-level flat discount, capped at the line value.
                    $lineDiscount = min($discountAmount, $baseRate * abs($qty));
                    $rate = ($baseRate * abs($qty) - $lineDiscount) / max(0.000001, abs($qty));
                }

                $amount = round($qty * $rate, 6); // negative for returns

                // Tax handling -----------------------------------------------------
                $tax = null;
                $taxRate = 0.0;
                $taxIncluded = $profile->tax_inclusive;
                if (! empty($line['tax_id'])) {
                    $tax = Tax::findOrFail($line['tax_id']);
                    $taxRate = (float) $tax->rate;
                    $taxIncluded = $tax->included_in_price;
                }
                $taxAmount = 0.0;
                $netAmount = $amount;
                if ($taxRate > 0) {
                    if ($taxIncluded) {
                        $netAmount = $amount / (1 + $taxRate / 100);
                        $taxAmount = $amount - $netAmount;
                    } else {
                        $taxAmount = $amount * $taxRate / 100;
                    }
                }

                $invoiceItem = new InvoiceItem([
                    'item_id' => $item->id,
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'uom' => $uomName,
                    'conversion_factor' => $conversion,
                    'qty' => $qty,
                    'price_list_rate' => round($listRate, 6),
                    'rate' => round($rate, 6),
                    'discount_percentage' => $discountPercentage,
                    'discount_amount' => round($lineDiscount, 6),
                    'amount' => round($amount, 6),
                    'tax_id' => $tax?->id,
                    'tax_rate' => $taxRate,
                    'tax_amount' => round($taxAmount, 6),
                    'tax_included' => $taxIncluded,
                    'warehouse_id' => $line['warehouse_id'] ?? $warehouseId,
                    'batch_no' => $line['batch_no'] ?? null,
                    'serial_no' => $line['serial_no'] ?? null,
                    'note' => $line['note'] ?? null,
                ]);
                $invoice->items()->save($invoiceItem);

                if ($tax && $taxAmount != 0.0) {
                    if (! isset($taxBreakdown[$tax->id])) {
                        $taxBreakdown[$tax->id] = ['tax' => $tax, 'amount' => 0.0];
                    }
                    $taxBreakdown[$tax->id]['amount'] += $taxAmount;
                }

                $netTotal += $netAmount;
                $taxTotal += $taxAmount;
                $discountTotal += $lineDiscount;
                $totalQty += $qty;

                // Stock ------------------------------------------------------------
                if ($item->is_stock_item) {
                    $this->stock->postMovement(
                        item: $item,
                        warehouseId: $invoiceItem->warehouse_id,
                        qtyChange: -($qty * $conversion), // return qty<0 => stock back in
                        refType: $isReturn ? 'return' : 'invoice',
                        refId: (string) $invoice->id,
                        allowNegative: $profile->allow_negative_stock ?: $setting->allow_negative_stock,
                    );
                }
            }

            // ---- Invoice-level (additional) discount ---------------------------------
            // Applied on the tax-inclusive total (net + tax) and spread
            // proportionally over net and tax, like ERPNext's
            // "Apply Discount On: Grand Total". Returns never carry one: their
            // rates are already taken from the discounted original sale.
            $additionalDiscount = 0.0;
            $additionalPercentage = 0.0;
            $additionalAmount = 0.0;
            $requestedPercentage = $isReturn ? 0.0 : (float) ($data['additional_discount_percentage'] ?? 0);
            $requestedAmount = $isReturn ? 0.0 : (float) ($data['additional_discount_amount'] ?? 0);
            if ($requestedPercentage > 0 || $requestedAmount > 0) {
                if (! $profile->allow_discount_change) {
                    throw ValidationException::withMessages([
                        'additional_discount' => ["Discounts are disabled for POS profile '{$profile->name}'."],
                    ]);
                }
                $preDiscountTotal = $netTotal + $taxTotal;
                if ($requestedPercentage > 0) {
                    $additionalPercentage = min(100.0, $requestedPercentage);
                    $additionalDiscount = $preDiscountTotal * $additionalPercentage / 100;
                } else {
                    $additionalDiscount = min($requestedAmount, max(0.0, $preDiscountTotal));
                    $additionalAmount = $additionalDiscount;
                    $additionalPercentage = $preDiscountTotal > 0 ? ($additionalDiscount / $preDiscountTotal) * 100 : 0.0;
                }
                if ($profile->max_discount_percentage > 0
                    && $additionalPercentage > $profile->max_discount_percentage + 0.0001) {
                    throw ValidationException::withMessages([
                        'additional_discount' => [sprintf('Discount %.2f%% exceeds the profile limit of %.2f%%.', $additionalPercentage, $profile->max_discount_percentage)],
                    ]);
                }
                $keep = $preDiscountTotal > 0 ? 1 - ($additionalDiscount / $preDiscountTotal) : 1.0;
                $netTotal *= $keep;
                $taxTotal *= $keep;
                foreach ($taxBreakdown as $taxId => $entry) {
                    $taxBreakdown[$taxId]['amount'] = $entry['amount'] * $keep;
                }
                $discountTotal += $additionalDiscount;
                if ($requestedPercentage > 0) {
                    $additionalAmount = $additionalDiscount;
                }
            }

            // ---- Taxes summary ----------------------------------------------------
            foreach ($taxBreakdown as $entry) {
                $invoice->taxes()->create([
                    'tax_id' => $entry['tax']->id,
                    'description' => $entry['tax']->description ?? $entry['tax']->name,
                    'rate' => $entry['tax']->rate,
                    'amount' => round($entry['amount'], 6),
                    'included_in_price' => $entry['tax']->included_in_price,
                ]);
            }

            // ---- Coupon -----------------------------------------------------------
            $couponDiscount = 0.0;
            if (! empty($data['coupon_code'])) {
                $coupon = Coupon::where('coupon_code', $data['coupon_code'])->first();
                if ($coupon) {
                    // Throws ValidationException when inactive / expired / over-used / below minimum.
                    $couponDiscount = $this->coupons->validateAndCompute($coupon, $netTotal, $customer->id);
                    $coupon->increment('used_count');
                } else {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['Coupon code is not valid.'],
                    ]);
                }
            }

            // ---- Loyalty redemption (1 point = 1 currency unit) ---------------------
            $pointsSpent = min((int) ($data['loyalty_points'] ?? 0), $customer->loyalty_points);
            $loyaltyDiscount = 0.0;
            if ($pointsSpent > 0) {
                $loyaltyDiscount = min((float) $pointsSpent, max(0.0, $netTotal + $taxTotal - $couponDiscount));
                $customer->decrement('loyalty_points', $pointsSpent);
                $invoice->loyalty_points_spent = $pointsSpent;
            }

            // ---- Payments -----------------------------------------------------------
            $paidAmount = 0.0;
            $allowedModes = $profile->paymentModes();
            foreach ($data['payments'] ?? [] as $pay) {
                $mode = $pay['mode_of_payment'];
                if ($allowedModes && ! in_array($mode, $allowedModes, true)) {
                    throw ValidationException::withMessages([
                        'payments' => ["Payment mode '{$mode}' is not enabled for POS profile '{$profile->name}'."],
                    ]);
                }
                $amount = (float) $pay['amount'];
                if ($isReturn) {
                    $amount = -abs($amount);
                }
                $invoice->payments()->create([
                    'mode_of_payment' => $mode,
                    'amount' => round($amount, 6),
                    'reference_no' => $pay['reference_no'] ?? null,
                    'reference_date' => $pay['reference_date'] ?? null,
                ]);
                $paidAmount += $amount;
            }

            // ---- Grand totals --------------------------------------------------------
            $grandTotal = $netTotal + $taxTotal - $couponDiscount - $loyaltyDiscount;
            $changeAmount = max(0.0, $paidAmount - $grandTotal);
            $outstanding = round($grandTotal - $paidAmount + $changeAmount, $precision);

            // A short payment is a CREDIT sale: it must fit the customer's credit limit.
            if (! $isReturn && $outstanding > 0.009) {
                app(CreditService::class)->assertCreditAllowed($customer, $outstanding);
            }

            $pointsEarned = 0;
            if (! $isReturn && ! $customer->is_walk_in && $netTotal >= 100) {
                // Simple default policy: 1 point per 100.00 spent (see README roadmap).
                $pointsEarned = (int) floor($netTotal / 100);
                $customer->increment('loyalty_points', $pointsEarned);
            }

            if ($isCredit && $outstanding <= 0.009) {
                throw ValidationException::withMessages([
                    'payments' => ['Nothing is left to put on credit — the payments already cover the total.'],
                ]);
            }

            $status = Invoice::STATUS_PAID;
            if ($outstanding > 0.009) {
                $status = $paidAmount > 0 ? Invoice::STATUS_PARTLY_PAID : Invoice::STATUS_UNPAID;
            }
            if ($isReturn) {
                $status = Invoice::STATUS_RETURNED;
                if ($returnAgainst) {
                    $stillReturnable = 0.0;
                    foreach ($returnable as $itemId => $row) {
                        $stillReturnable += max(0.0, $row['remaining'] - ($returnTotals[$itemId] ?? 0.0));
                    }
                    if ($stillReturnable <= 0.0001) {
                        $returnAgainst->status = Invoice::STATUS_RETURNED;
                        $returnAgainst->save();
                    }
                }
            }

            $invoice->fill([
                'status' => $status,
                'total_qty' => round($totalQty, 6),
                'net_total' => round($netTotal, $precision),
                'tax_total' => round($taxTotal, $precision),
                'discount_total' => round($discountTotal, $precision),
                'additional_discount_percentage' => round($requestedPercentage > 0 ? $additionalPercentage : 0.0, 6),
                'additional_discount_amount' => round($additionalAmount, $precision),
                'grand_total' => round($grandTotal, $precision),
                'rounded_total' => round($grandTotal, $precision),
                'paid_amount' => round($paidAmount, $precision),
                'change_amount' => round($changeAmount, $precision),
                'outstanding_amount' => round($outstanding, $precision),
                'is_credit' => $isCredit,
                'due_date' => $dueDate?->toDateString(),
                'credit_days' => $creditDays,
                'coupon_discount' => round($couponDiscount, 6),
                'loyalty_points_earned' => $pointsEarned,
            ])->save();

            if (! $isReturn && $outstanding > 0.009) {
                app(CreditService::class)->registerFromInvoice($invoice->load('items'));
            }

            return ['invoice' => $invoice->load(['items', 'payments', 'taxes', 'customer', 'cashier']), 'duplicate' => false];
        });
    }

    /**
     * What can still be returned on a sale, per item (keyed by item_id).
     *
     * `rate` is the unit rate actually charged (line discount and the
     * invoice-level discount already folded in), so a refund always matches
     * what the customer paid — never today's price list.
     *
     * @return array<int, array{item_id:int,item_name:string,item_code:string,uom:string,conversion_factor:float,
     *     tax_id:int|null,tax_rate:float,tax_included:bool,sold:float,returned:float,remaining:float,rate:float}>
     */
    public function returnableLines(Invoice $invoice): array
    {
        $invoice->loadMissing('items');

        $preAdditional = 0.0;
        foreach ($invoice->items as $line) {
            $preAdditional += (float) $line->amount + ($line->tax_included ? 0.0 : (float) $line->tax_amount);
        }
        $additional = (float) $invoice->additional_discount_amount;
        $keep = ($additional > 0 && $preAdditional > 0) ? max(0.0, 1 - $additional / $preAdditional) : 1.0;

        $returned = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.return_against_id', $invoice->id)
            ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
            ->groupBy('invoice_items.item_id')
            ->selectRaw('invoice_items.item_id, SUM(ABS(invoice_items.qty)) as qty')
            ->pluck('qty', 'item_id');

        $rows = [];
        foreach ($invoice->items as $line) {
            if (! $line->item_id || (float) $line->qty <= 0) {
                continue;
            }
            $row = $rows[$line->item_id] ?? [
                'item_id' => (int) $line->item_id,
                'item_name' => $line->item_name,
                'item_code' => $line->item_code,
                'uom' => $line->uom,
                'conversion_factor' => (float) $line->conversion_factor,
                'tax_id' => $line->tax_id,
                'tax_rate' => (float) $line->tax_rate,
                'tax_included' => (bool) $line->tax_included,
                'sold' => 0.0,
                'value' => 0.0,
            ];
            $row['sold'] += (float) $line->qty;
            $row['value'] += (float) $line->amount;
            $rows[$line->item_id] = $row;
        }

        foreach ($rows as $itemId => $row) {
            $alreadyReturned = (float) ($returned[$itemId] ?? 0);
            $rows[$itemId] = [
                'item_id' => $row['item_id'],
                'item_name' => $row['item_name'],
                'item_code' => $row['item_code'],
                'uom' => $row['uom'],
                'conversion_factor' => $row['conversion_factor'],
                'tax_id' => $row['tax_id'],
                'tax_rate' => $row['tax_rate'],
                'tax_included' => $row['tax_included'],
                'sold' => $row['sold'],
                'returned' => $alreadyReturned,
                'remaining' => max(0.0, $row['sold'] - $alreadyReturned),
                'rate' => $row['sold'] > 0 ? ($row['value'] / $row['sold']) * $keep : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * Why this sale cannot be cancelled by this user, or null when it can.
     *
     * The single guard behind every cancel entry point (web, API) and behind
     * the Cancel button, so the screens and the rules never disagree.
     */
    public function cancellationBlocker(Invoice $invoice, User $user): ?string
    {
        if ($invoice->status === Invoice::STATUS_CANCELLED) {
            return 'This invoice is already cancelled.';
        }
        if ($invoice->is_return) {
            return 'A return cannot be cancelled. Make a new sale if it was recorded by mistake.';
        }
        if (! $user->isManager() && $invoice->user_id !== $user->id) {
            return 'Only the cashier who made the sale, or a manager, can cancel it.';
        }
        if ($invoice->returns()->where('status', '!=', Invoice::STATUS_CANCELLED)->exists()) {
            return 'This sale already has returns. Cancel is not possible; the returns stand.';
        }
        if ($invoice->creditInvoice()->exists() && $invoice->creditInvoice->collections()->exists()) {
            return 'Money has been collected against this credit sale, so it cannot be cancelled. Use Return instead.';
        }
        if ($invoice->erpnext_id || $invoice->sync_status === 'synced') {
            return 'This sale is already in ERPNext. Use Return to reverse it instead of cancelling.';
        }

        $shift = $invoice->shift_id ? PosOpeningShift::find($invoice->shift_id) : null;
        if ($shift && ! $shift->isOpen()) {
            if (! $user->isManager()) {
                return 'This sale belongs to a closed shift. Only a manager can cancel it.';
            }
            if ($this->refundDue($invoice) > 0.009 && ! $this->openShiftFor($user)) {
                return 'Open a shift first, so the refund to the customer can be recorded in it.';
            }
        }

        return null;
    }

    /** Money actually kept from the customer: what they paid minus the change given back. */
    public function refundDue(Invoice $invoice): float
    {
        return max(0.0, round((float) $invoice->paid_amount - (float) $invoice->change_amount, 6));
    }

    /**
     * Cancel a sale. The row is kept (status "cancelled" plus who / when / why):
     *  - stock comes back,
     *  - the payments are voided: cancelled sales drop out of shift and daily totals,
     *    and when the sale's shift is already closed the refund is booked as a
     *    cash-out in the manager's current shift,
     *  - coupon use and loyalty points are given back,
     *  - nothing is deleted.
     *
     * @throws ValidationException when the guard refuses or the reason is empty
     */
    public function cancel(Invoice $invoice, User $user, string $reason): Invoice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why the sale is being cancelled.']]);
        }

        // Money already collected against a credit sale can't silently vanish.
        $credit = $invoice->creditInvoice;
        if ($credit && $credit->collections()->exists()) {
            throw ValidationException::withMessages([
                'invoice' => ["Invoice {$invoice->invoice_no} has collections recorded against it and cannot be cancelled."],
            ]);
        }

        return DB::transaction(function () use ($invoice, $user, $reason, $credit) {
            // Lock + re-read so two clicks (or two terminals) cannot cancel twice.
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($message = $this->cancellationBlocker($invoice, $user)) {
                throw ValidationException::withMessages(['cancel' => [$message]]);
            }

            $invoice->load(['items.item', 'payments']);

            if ($credit) {
                $credit->update(['status' => CreditInvoice::STATUS_CANCELLED]);
            }

            // Put stock back for a cancelled sale (or take it back for a cancelled return).
            foreach ($invoice->items as $line) {
                if ($line->item && $line->item->is_stock_item) {
                    $this->stock->postMovement(
                        item: $line->item,
                        warehouseId: $line->warehouse_id,
                        qtyChange: ($line->qty * $line->conversion_factor), // inverse of the sale
                        refType: 'adjustment',
                        refId: (string) $invoice->id,
                        allowNegative: false,
                    );
                }
            }

            if ($invoice->coupon_code) {
                Coupon::where('coupon_code', $invoice->coupon_code)->where('used_count', '>', 0)->decrement('used_count');
            }
            $customer = $invoice->customer;
            if ($customer && ! $customer->is_walk_in) {
                if ($invoice->loyalty_points_spent > 0) {
                    $customer->increment('loyalty_points', $invoice->loyalty_points_spent);
                }
                if ($invoice->loyalty_points_earned > 0) {
                    $customer->decrement('loyalty_points', min((int) $customer->loyalty_points, $invoice->loyalty_points_earned));
                }
            }

            $this->bookRefundInCurrentShift($invoice, $user, $reason);

            $invoice->forceFill([
                'status' => Invoice::STATUS_CANCELLED,
                'outstanding_amount' => 0, // nothing is owed on a cancelled sale
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => mb_substr($reason, 0, 500),
            ])->save();

            return $invoice->fresh(['items', 'payments', 'taxes']);
        });
    }

    /**
     * A sale from an already-closed shift was counted in that shift's cash.
     * Handing the money back now is a cash-out of the manager's open shift.
     */
    protected function bookRefundInCurrentShift(Invoice $invoice, User $user, string $reason): void
    {
        $shift = $invoice->shift_id ? PosOpeningShift::find($invoice->shift_id) : null;
        if (! $shift || $shift->isOpen()) {
            return; // still in the live shift: the voided payments simply leave its totals
        }
        $current = $this->openShiftFor($user);
        if (! $current) {
            return; // the guard already refuses this case when money is involved
        }

        $changeLeft = (float) $invoice->change_amount;
        foreach ($invoice->payments as $payment) {
            $net = (float) $payment->amount;
            $takeOff = min($changeLeft, $net);
            $net -= $takeOff;
            $changeLeft -= $takeOff;
            if ($net <= 0.009) {
                continue;
            }
            PosCashMovement::create([
                'shift_id' => $current->id,
                'user_id' => $user->id,
                'type' => PosCashMovement::TYPE_OUT,
                'mode_of_payment' => $payment->mode_of_payment,
                'amount' => round($net, 6),
                'reason' => "Refund: cancelled {$invoice->invoice_no} — {$reason}",
            ]);
        }
    }

    protected function openShiftFor(User $user): ?PosOpeningShift
    {
        return PosOpeningShift::where('user_id', $user->id)
            ->where('status', PosOpeningShift::STATUS_OPEN)
            ->first();
    }

    protected function resolveCustomer(array $data): Customer
    {
        if (! empty($data['customer_id'])) {
            return Customer::findOrFail($data['customer_id']);
        }

        $walkIn = Customer::where('is_walk_in', true)->first();
        if ($walkIn) {
            return $walkIn;
        }

        return Customer::create([
            'customer_code' => $this->nextCode('customers', 'customer_code', 'CUST-'),
            'customer_name' => 'Walk-in Customer',
            'is_walk_in' => true,
        ]);
    }

    protected function conversionFactor(Item $item, string $uomName): float
    {
        $uom = Uom::where('uom_name', $uomName)->first();
        if (! $uom) {
            return 1.0;
        }
        if ($item->stock_uom_id === $uom->id) {
            return 1.0;
        }

        $conversion = $item->uomConversions()->where('uom_id', $uom->id)->value('conversion_factor');

        return $conversion ? (float) $conversion : 1.0;
    }

    /**
     * {ERPNext series prefix}{VAN}-[CR-]{YYYY}-##### — e.g. ACC-SINV-TVP-2026-00001.
     *
     * The prefix is ERPNext's Sales Invoice naming series (pulled on sync; the
     * local `invoice_prefix` until then) and the van id comes from the POS
     * profile, so two vans can never mint the same number. The counter lives in
     * settings and is bumped inside the current transaction.
     */
    protected function nextInvoiceNumber(Setting $setting, PosProfile $profile, bool $isReturn): string
    {
        // invoice_prefix is ERPNext's Sales Invoice series, kept current by PosProfileSync / NamingSeries.
        $prefix = DocNaming::seriesPrefix((string) $setting->invoice_series) ?: (string) $setting->invoice_prefix;

        $seq = $setting->next_invoice_number;
        $setting->next_invoice_number = $seq + 1;
        $setting->save();
        Setting::flushCache();

        return DocNaming::number($prefix, $profile->ensureVanCode(), $isReturn ? 'CR' : '', now()->year, $seq);
    }

    protected function nextCode(string $table, string $column, string $prefix): string
    {
        $max = DB::table($table)->max($column);
        $seq = $max ? (int) substr($max, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
