<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\ErpNextClient;
use App\Services\SyncService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        protected SyncService $sync,
        protected ErpNextClient $client,
    ) {}

    /**
     * GET /api/v1/sync/status — connector health + pending push count.
     */
    public function status()
    {
        $setting = Setting::current();
        $configured = (bool) ($setting->erpnext_url && $setting->erpnext_api_key && $setting->erpnext_api_secret)
            || config('services.erpnext.url');

        $reachable = null;
        $pingMessage = null;
        if ($configured) {
            try {
                $pingMessage = $this->client->ping()['message'] ?? null;
                $reachable = true;
            } catch (\Throwable $e) {
                $reachable = false;
                $pingMessage = $e->getMessage();
            }
        }

        return response()->json([
            'data' => [
                'configured' => $configured,
                'reachable' => $reachable,
                'ping' => $pingMessage,
                'url' => $setting->erpnext_url ?: config('services.erpnext.url'),
                'company' => $setting->erpnext_company ?: config('services.erpnext.company'),
                'price_list' => $setting->erpnext_price_list ?: config('services.erpnext.price_list') ?: 'Standard Selling',
                'last_synced_at' => $setting->last_synced_at?->toDateTimeString(),
                'pending_invoices' => Invoice::where('sync_status', 'pending')->where('status', '!=', 'cancelled')->count(),
                'failed_invoices' => Invoice::where('sync_status', 'failed')->count(),
            ],
        ]);
    }

    /**
     * POST /api/v1/sync/masters — pull master data from ERPNext into local SQLite.
     * Body: {entities?: ["uom","item_group","warehouse","item","item_price",
     *                     "customer_group","territory","tax","customer","stock"],
     *        limit?: 500}
     */
    public function pullMasters(Request $request)
    {
        abort_unless($request->user()->isManager(), 403, 'Only managers can run a master sync.');

        $valid = ['uom', 'item_group', 'warehouse', 'customer_group', 'territory', 'tax', 'item', 'item_price', 'customer', 'stock'];
        $data = $request->validate([
            'entities' => ['nullable', 'array'],
            'entities.*' => ['string', 'in:'.implode(',', $valid)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $entities = $data['entities'] ?? ['uom', 'item_group', 'warehouse', 'customer_group', 'territory', 'tax', 'item', 'item_price', 'customer', 'stock'];

        try {
            $result = $this->sync->pullMasters($entities, $data['limit'] ?? 500);
        } catch (\Throwable $e) {
            return response()->json([
                'data' => ['message' => $e->getMessage()],
            ], 502);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * POST /api/v1/sync/push-invoices — push pending invoices to ERPNext.
     * Body: {invoice_ids?: [1,2,3]}
     */
    public function pushInvoices(Request $request)
    {
        abort_unless($request->user()->isManager(), 403, 'Only managers can push invoices to ERPNext.');

        $data = $request->validate([
            'invoice_ids' => ['nullable', 'array'],
            'invoice_ids.*' => ['integer'],
        ]);

        try {
            $result = $this->sync->pushInvoices($data['invoice_ids'] ?? null);
        } catch (\Throwable $e) {
            return response()->json([
                'data' => ['message' => $e->getMessage()],
            ], 502);
        }

        return response()->json(['data' => $result]);
    }
}
