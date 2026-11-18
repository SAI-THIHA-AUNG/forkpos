<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\CreditInvoice;
use App\Models\Customer;
use App\Models\PosProfile;
use App\Services\CreditService;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Credit sales: who owes what (all vans), and collecting it.
 * All reads come from the local database so the screens work offline.
 */
class CreditController extends Controller
{
    public function __construct(protected CreditService $credit, protected SyncService $sync) {}

    /** GET /credit — every open credit invoice, from every van. */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $van = $request->query('van');

        $invoices = CreditInvoice::open()->withPending()->with('customer')
            ->when($q, fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('invoice_no', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$q}%"));
            }))
            ->when($van, fn ($query) => $query->where('van_code', $van))
            ->orderBy('due_date')->orderBy('id')
            ->get()
            ->filter(fn (CreditInvoice $c) => $c->outstanding > 0.009)
            ->values();

        return view('pos.credit.index', [
            'invoices' => $invoices,
            'total' => $invoices->sum(fn ($c) => $c->outstanding),
            'q' => $q,
            'van' => $van,
            'vans' => CreditInvoice::whereNotNull('van_code')->distinct()->orderBy('van_code')->pluck('van_code'),
            'failed' => Collection::where('sync_status', Collection::FAILED)->with(['creditInvoice', 'customer'])->orderByDesc('id')->get(),
        ]);
    }

    /** GET /credit/{credit} — invoice detail with lines and collection history. */
    public function show(CreditInvoice $credit)
    {
        $credit->load(['customer', 'collections.cashier', 'invoice']);

        return view('pos.credit.show', ['credit' => $credit]);
    }

    /** GET /credit/customer/{customer} — what the register's credit alert shows (JSON). */
    public function customer(Customer $customer)
    {
        return response()->json(['data' => $this->credit->customerSummary($customer)]);
    }

    /** GET /credit/{credit}/detail — one invoice's detail for the register slide-over (JSON). */
    public function detail(CreditInvoice $credit)
    {
        return response()->json(['data' => $this->detailPayload($credit)]);
    }

    /** POST /credit/{credit}/collect — quick collect from a list, the register drawer or the detail page. */
    public function collect(Request $request, CreditInvoice $credit)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode_of_payment' => ['required', 'string', 'max:64'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'offline_id' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var PosProfile $profile */
        $profile = $request->attributes->get('posProfile');

        try {
            $collection = $this->credit->collect($credit, $data, $request->user(), $profile);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Collection failed.', 'errors' => $e->errors()], 422);
            }
            throw $e;
        }

        $credit->refresh();
        $message = sprintf('Collected %s on %s (%s).', number_format($collection->amount, 2), $credit->invoice_no, $collection->collection_no);

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'collection_no' => $collection->collection_no,
                'outstanding' => $credit->outstanding,
                'message' => $message,
            ]], 201);
        }

        return back()->with('success', $message);
    }

    /** POST /credit/collections/{collection}/discard — drop a rejected collection so the balance is open again. */
    public function discard(Collection $collection)
    {
        $this->credit->discard($collection);

        return back()->with('success', "Collection {$collection->collection_no} discarded.");
    }

    /** POST /credit/refresh — pull the latest unpaid invoices (and push waiting collections first). */
    public function refresh(Request $request)
    {
        try {
            $push = $this->sync->pushCollections();
            $result = $this->sync->pullMasters(['customer', 'credit_invoice']);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not refresh credit invoices: '.$e->getMessage());
        }

        if ($result['errors']) {
            return back()->with('error', 'Refresh failed: '.implode(' | ', $result['errors']));
        }

        $n = $result['results']['credit_invoice']['pulled'] ?? 0;
        $msg = "Credit invoices refreshed: {$n} unpaid in ERPNext.";
        if ($push['pushed'] || $push['failed']) {
            $msg .= " Collections pushed {$push['pushed']}, rejected {$push['failed']}.";
        }

        return back()->with($push['failed'] ? 'warning' : 'success', $msg);
    }

    /** @return array<string, mixed> */
    protected function detailPayload(CreditInvoice $credit): array
    {
        $credit = CreditInvoice::withPending()->with(['customer', 'collections'])->findOrFail($credit->id);

        return $this->credit->row($credit) + [
            'customer' => $credit->customer?->customer_name,
            'paid' => (float) $credit->paid_amount + $credit->pending_collected,
            'items' => $credit->items ?? [],
            'collections' => $credit->collections->map(fn (Collection $c) => [
                'collection_no' => $c->collection_no,
                'amount' => (float) $c->amount,
                'mode' => $c->mode_of_payment,
                'date' => $c->posting_date?->toDateString(),
                'status' => $c->sync_status,
            ])->all(),
            'url' => route('credit.show', $credit),
        ];
    }
}
