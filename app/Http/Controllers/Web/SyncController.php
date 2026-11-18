<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\CreditInvoice;
use App\Models\Invoice;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Services\ErpNextClient;
use App\Services\SyncContext;
use App\Services\SyncService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        protected SyncService $sync,
        protected ErpNextClient $client,
    ) {}

    public function index()
    {
        $setting = Setting::current();
        $configured = $setting->erpnextEnabled();

        $reachable = null;
        $ping = null;
        if ($configured) {
            try {
                $ping = 'Authenticated as '.$this->client->verify()['user'];
                $reachable = true;
            } catch (\Throwable $e) {
                $reachable = false;
                $ping = $e->getMessage();
            }
        }

        $recent = Invoice::whereIn('sync_status', ['pending', 'failed'])
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'invoice_no', 'status', 'sync_status', 'erpnext_id', 'posting_datetime']);

        return view('pos.sync.index', [
            'setting' => $setting,
            'configured' => $configured,
            'reachable' => $reachable,
            'ping' => $ping,
            'pendingCount' => Invoice::where('sync_status', 'pending')->where('status', '!=', 'cancelled')->count(),
            'failedCount' => Invoice::where('sync_status', 'failed')->count(),
            'syncedCount' => Invoice::where('sync_status', 'synced')->count(),
            'collectionsPending' => Collection::where('sync_status', Collection::PENDING)->count(),
            'collectionsFailed' => Collection::where('sync_status', Collection::FAILED)->count(),
            'openCredit' => CreditInvoice::open()->count(),
            'recent' => $recent,
        ]);
    }

    public function pullMasters(Request $request)
    {
        $valid = ['uom', 'item_group', 'warehouse', 'customer_group', 'territory', 'tax', 'item', 'item_price', 'customer', 'naming_series', 'credit_invoice', 'stock'];

        // The form is a single free-text field: "uom,item,stock". Normalise it
        // into the array the validator (and the sync service) expect.
        $request->merge([
            'entities' => collect((array) $request->input('entities', []))
                ->flatMap(fn ($value) => explode(',', (string) $value))
                ->map(fn ($value) => trim($value))
                ->filter()
                ->values()
                ->all(),
        ]);

        $data = $request->validate([
            'entities' => ['nullable', 'array'],
            'entities.*' => ['string', 'in:'.implode(',', $valid)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);
        $entities = $data['entities'] ?? [];
        if (! $entities) {
            $entities = $valid;
        }

        // Scope the pull to the POS profile the cashier picked (warehouse,
        // price list, item/customer groups, tax template) — the same context
        // the queued catalog sync uses. Without a selection, pull globally.
        $profile = $request->session()->get('pos_profile_id')
            ? PosProfile::find($request->session()->get('pos_profile_id'))
            : null;
        $ctx = $profile ? SyncContext::forProfile($profile) : null;

        // A first full pull of a large ERPNext catalog easily outlasts PHP's default
        // 30 s web limit ("Maximum execution time of 30 seconds exceeded").
        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $result = $this->sync->pullMasters($entities, $data['limit'] ?? 500, $ctx);

            $msg = collect($result['results'] ?? [])
                ->map(fn ($s, $k) => "{$k}: {$s['pulled']} pulled, {$s['upserted']} upserted")
                ->implode('; ');
            $errors = $result['errors'] ?? [];

            $summary = $msg !== '' ? "Master sync done ({$msg})." : 'Master sync done.';
            if ($errors) {
                return back()->with('warning', $summary.' Problems: '.implode(' | ', $errors));
            }

            return back()->with('success', $summary);
        } catch (\Throwable $e) {
            return back()->with('error', 'Master sync failed: '.$e->getMessage());
        }
    }

    public function pushInvoices(Request $request)
    {
        // "1,2,3" from the free-text field → [1, 2, 3].
        $request->merge([
            'invoice_ids' => collect((array) $request->input('invoice_ids', []))
                ->flatMap(fn ($value) => explode(',', (string) $value))
                ->map(fn ($value) => trim($value))
                ->filter()
                ->values()
                ->all(),
        ]);

        $data = $request->validate([
            'invoice_ids' => ['nullable', 'array'],
            'invoice_ids.*' => ['integer'],
        ]);

        $ids = $data['invoice_ids'] ?? [];
        // An empty selection means "all pending".
        $ids = $ids ? array_map('intval', $ids) : null;

        try {
            $result = $this->sync->pushInvoices($ids);
            $pushed = $result['pushed'] ?? 0;
            $failed = $result['failed'] ?? 0;
            $errors = $result['errors'] ?? [];

            $message = "Pushed {$pushed} invoice(s), failed {$failed}.";

            // Collections go after invoices: a Payment Entry needs its invoice in ERPNext first.
            if (! $ids) {
                $col = $this->sync->pushCollections();
                $message .= " Collections: pushed {$col['pushed']}, failed {$col['failed']}.";
                $failed += $col['failed'];
                $errors = array_merge($errors, $col['errors']);
            }
            if ($errors) {
                $message .= ' '.implode(' | ', array_slice($errors, 0, 5));
            }

            return back()->with($failed > 0 ? 'warning' : 'success', $message);
        } catch (\Throwable $e) {
            return back()->with('error', 'Push failed: '.$e->getMessage());
        }
    }
}
