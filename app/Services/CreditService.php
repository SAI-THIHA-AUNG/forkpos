<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\CreditInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PosOpeningShift;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\User;
use App\Support\DocNaming;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credit sales and collections.
 *
 * A credit sale is a normal POS invoice that was not paid in full. Its balance is
 * tracked in `credit_invoices`, next to the unpaid invoices pulled from ERPNext
 * (made by ANY van), so a salesman can collect on both the same way.
 */
class CreditService
{
    /** Track the unpaid balance of a freshly created local invoice. */
    public function registerFromInvoice(Invoice $invoice): CreditInvoice
    {
        $outstanding = (float) $invoice->outstanding_amount;

        return CreditInvoice::updateOrCreate(['invoice_id' => $invoice->id], [
            'customer_id' => $invoice->customer_id,
            'invoice_no' => $invoice->invoice_no,
            'van_code' => $invoice->van_code,
            'origin' => 'local',
            'posting_date' => $invoice->posting_date,
            // The terms the cashier picked at the register win; the config default only fills a gap.
            'due_date' => $invoice->due_date ?? $invoice->posting_date->copy()->addDays((int) config('forkpos.credit_days', 30)),
            'currency' => $invoice->currency,
            'grand_total' => $invoice->grand_total,
            'paid_amount' => round((float) $invoice->grand_total - $outstanding, 6),
            'erp_outstanding' => $outstanding,
            'status' => CreditInvoice::STATUS_OPEN,
            'items' => $invoice->items->map(fn ($l) => [
                'item_code' => $l->item_code,
                'item_name' => $l->item_name,
                'qty' => (float) $l->qty,
                'uom' => $l->uom,
                'rate' => (float) $l->rate,
                'amount' => (float) $l->amount,
            ])->values()->all(),
        ]);
    }

    /**
     * Credit has to stay inside the customer's credit limit (0 = no limit). The register
     * additionally asks for a named customer; the API stays lenient for existing clients.
     */
    public function assertCreditAllowed(Customer $customer, float $newOutstanding): void
    {
        $limit = (float) $customer->credit_limit;
        if ($limit > 0) {
            $owed = $this->customerOutstanding($customer);
            if ($owed + $newOutstanding > $limit + 0.009) {
                throw ValidationException::withMessages([
                    'payments' => [sprintf(
                        '%s is over the credit limit: owes %s, this sale adds %s, limit is %s.',
                        $customer->customer_name, number_format($owed, 2), number_format($newOutstanding, 2), number_format($limit, 2),
                    )],
                ]);
            }
        }
    }

    public function customerOutstanding(Customer $customer): float
    {
        return round($this->openInvoices($customer)->sum(fn (CreditInvoice $c) => $c->outstanding), 6);
    }

    /** Open invoices with a real balance left, oldest first. */
    public function openInvoices(Customer $customer)
    {
        return CreditInvoice::open()
            ->where('customer_id', $customer->id)
            ->withPending()
            ->orderBy('posting_date')->orderBy('id')
            ->get()
            ->filter(fn (CreditInvoice $c) => $c->outstanding > 0.009)
            ->values();
    }

    /**
     * What the register shows when a customer is picked: count, total, and the
     * invoice list for the slide-over. Everything comes from the local database,
     * so it works offline.
     *
     * @return array{count:int,total:float,overdue:int,currency:?string,invoices:array<int,array<string,mixed>>}
     */
    public function customerSummary(Customer $customer): array
    {
        $open = $this->openInvoices($customer);

        return [
            'count' => $open->count(),
            'total' => round($open->sum(fn ($c) => $c->outstanding), 6),
            'overdue' => $open->filter(fn ($c) => $c->isOverdue())->count(),
            'currency' => $open->first()?->currency,
            'invoices' => $open->map(fn (CreditInvoice $c) => $this->row($c))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function row(CreditInvoice $c): array
    {
        return [
            'id' => $c->id,
            'invoice_no' => $c->invoice_no,
            'van_code' => $c->van_code,
            'origin' => $c->origin,
            'posting_date' => $c->posting_date?->toDateString(),
            'due_date' => $c->due_date?->toDateString(),
            'grand_total' => (float) $c->grand_total,
            'outstanding' => $c->outstanding,
            'overdue' => $c->isOverdue(),
        ];
    }

    /**
     * Record a collection against a credit invoice.
     *
     * @param  array{amount: float|int|string, mode_of_payment: string, reference_no?: ?string, reference_date?: ?string, remarks?: ?string, offline_id?: ?string}  $data
     */
    public function collect(CreditInvoice $credit, array $data, User $user, ?PosProfile $profile = null): Collection
    {
        if (! empty($data['offline_id'])) {
            $existing = Collection::where('offline_id', $data['offline_id'])->first();
            if ($existing) {
                return $existing; // same request retried — return the first result
            }
        }

        return DB::transaction(function () use ($credit, $data, $user, $profile) {
            /** @var CreditInvoice $credit */
            $credit = CreditInvoice::whereKey($credit->id)->lockForUpdate()->firstOrFail();
            $precision = (int) (Setting::current()->currency_precision ?? 2);

            if ($credit->status !== CreditInvoice::STATUS_OPEN) {
                throw ValidationException::withMessages(['amount' => ["Invoice {$credit->invoice_no} has no balance to collect."]]);
            }

            $amount = round((float) $data['amount'], $precision);
            $outstanding = round($credit->outstanding, $precision);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Enter an amount greater than zero.']]);
            }
            if ($amount > $outstanding + 0.009) {
                throw ValidationException::withMessages(['amount' => [sprintf('Amount is more than the outstanding balance (%s).', number_format($outstanding, $precision))]]);
            }

            $mode = (string) $data['mode_of_payment'];
            $allowed = $profile ? $profile->paymentModes() : [];
            if ($allowed && ! in_array($mode, $allowed, true)) {
                throw ValidationException::withMessages(['mode_of_payment' => ["Payment mode '{$mode}' is not enabled for this POS profile."]]);
            }

            $shiftId = null;
            if ($profile && $profile->require_shift) {
                $shiftId = PosOpeningShift::where('user_id', $user->id)
                    ->where('pos_profile_id', $profile->id)
                    ->where('status', PosOpeningShift::STATUS_OPEN)
                    ->value('id');
                if (! $shiftId) {
                    throw ValidationException::withMessages(['shift_id' => ['No open shift found for this POS profile. Open a shift first.']]);
                }
            }

            $van = $profile?->ensureVanCode();
            $now = now();

            $collection = Collection::create([
                'collection_no' => $this->nextCollectionNumber($van, $now->year),
                'credit_invoice_id' => $credit->id,
                'customer_id' => $credit->customer_id,
                'pos_profile_id' => $profile?->id,
                'user_id' => $user->id,
                'shift_id' => $shiftId,
                'van_code' => $van,
                'mode_of_payment' => $mode,
                'amount' => $amount,
                'reference_no' => $data['reference_no'] ?? null,
                'reference_date' => $data['reference_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'posting_datetime' => $now,
                'posting_date' => $now->toDateString(),
                'offline_id' => $data['offline_id'] ?? null,
                'sync_status' => Collection::PENDING,
            ]);

            $left = round($outstanding - $amount, $precision);
            if ($left <= 0.009) {
                $credit->update(['status' => CreditInvoice::STATUS_SETTLED]);
            }
            $this->mirrorToLocalInvoice($credit, $left);

            return $collection;
        });
    }

    /** A rejected collection (e.g. another van already collected) can be dropped to free the balance again. */
    public function discard(Collection $collection): void
    {
        if ($collection->sync_status === Collection::SYNCED) {
            throw ValidationException::withMessages(['collection' => ['A collection already in ERPNext cannot be discarded.']]);
        }

        DB::transaction(function () use ($collection) {
            $credit = CreditInvoice::whereKey($collection->credit_invoice_id)->lockForUpdate()->firstOrFail();
            $collection->delete();

            $credit = $credit->fresh();
            if ($credit->status === CreditInvoice::STATUS_SETTLED && $credit->outstanding > 0.009) {
                $credit->update(['status' => CreditInvoice::STATUS_OPEN]);
            }
            $this->mirrorToLocalInvoice($credit, $credit->outstanding);
        });
    }

    /** Called once a collection reached ERPNext: ERPNext's balance now already includes it. */
    public function markPushed(Collection $collection, string $paymentEntry): void
    {
        DB::transaction(function () use ($collection, $paymentEntry) {
            $collection->update([
                'erpnext_id' => $paymentEntry,
                'sync_status' => Collection::SYNCED,
                'sync_error' => null,
            ]);
            $credit = CreditInvoice::whereKey($collection->credit_invoice_id)->lockForUpdate()->first();
            if ($credit) {
                $credit->update([
                    'erp_outstanding' => max(0.0, round((float) $credit->erp_outstanding - (float) $collection->amount, 6)),
                    'paid_amount' => round((float) $credit->paid_amount + (float) $collection->amount, 6),
                ]);
            }
        });
    }

    protected function mirrorToLocalInvoice(CreditInvoice $credit, float $left): void
    {
        if (! $credit->invoice_id) {
            return;
        }
        $left = max(0.0, $left);
        Invoice::whereKey($credit->invoice_id)
            // A returned or cancelled sale keeps its status; a collection must not revive it.
            ->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_RETURNED])
            ->update([
                'outstanding_amount' => $left,
                'paid_amount' => round((float) $credit->grand_total - $left, 6),
                // Nothing paid at all (e.g. after a rejected collection was discarded) is "unpaid", not "partly paid".
                'status' => $left <= 0.009
                    ? Invoice::STATUS_PAID
                    : ($left >= (float) $credit->grand_total - 0.009 ? Invoice::STATUS_UNPAID : Invoice::STATUS_PARTLY_PAID),
            ]);
    }

    /** COL-{VAN}-{YYYY}-##### — the van id keeps two vans' receipts apart. */
    protected function nextCollectionNumber(?string $van, int $year): string
    {
        $head = DocNaming::number('COL-', $van, '', $year, 0);
        $prefix = substr($head, 0, -5); // strip the zero-padded sequence
        $max = Collection::where('collection_no', 'like', $prefix.'%')->max('collection_no');
        $seq = $max ? ((int) substr($max, strlen($prefix))) + 1 : 1;

        return DocNaming::number('COL-', $van, '', $year, $seq);
    }
}
