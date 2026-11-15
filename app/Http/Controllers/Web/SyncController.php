<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\ErpNextClient;
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
        $setting = \App\Models\Setting::current();
        $configured = (bool) ($setting->erpnext_url && $setting->erpnext_api_key && $setting->erpnext_api_secret)
            || config('services.erpnext.url');

        $reachable = null;
        $ping = null;
        if ($configured) {
            try {
                $ping = $this->client->ping()['message'] ?? null;
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
            'recent' => $recent,
        ]);
    }

    public function pullMasters(Request $request)
    {
        $valid = ['uom', 'item_group', 'warehouse', 'customer_group', 'territory', 'tax', 'item', 'item_price', 'customer', 'stock'];
        $data = $request->validate([
            'entities' => ['nullable', 'array'],
            'entities.*' => ['string', 'in:'.implode(',', $valid)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);
        $entities = $data['entities'] ?? $valid;

        try {
            $result = $this->sync->pullMasters($entities, $data['limit'] ?? 500);
            $msg = collect($result['summary'] ?? [])->map(fn ($s, $k) => "{$k}: pulled={$s['pulled']}, errors={$s['errors']}")->implode(' | ');

            return back()->with('success', 'Master sync done. '.$msg);
        } catch (\Throwable $e) {
            return back()->with('error', 'Master sync failed: '.$e->getMessage());
        }
    }

    public function pushInvoices(Request $request)
    {
        $data = $request->validate([
            'invoice_ids' => ['nullable', 'array'],
            'invoice_ids.*' => ['integer'],
        ]);

        try {
            $result = $this->sync->pushInvoices($data['invoice_ids'] ?? null);
            $pushed = $result['pushed'] ?? 0;
            $failed = $result['failed'] ?? 0;

            return back()->with($failed > 0 ? 'warning' : 'success', "Pushed {$pushed} invoice(s), failed {$failed}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Push failed: '.$e->getMessage());
        }
    }
}
