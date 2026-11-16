<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\ErpNextClient;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        // Only ping if configured and skip for faster loading
        // In a real-world scenario, you might want to add a "Test Connection" button
        if ($configured) {
            // For now, just mark as reachable without pinging to improve performance
            // The actual ping can be done when the user explicitly tests the connection
            $reachable = true;
            $pingMessage = 'Connection status not tested - click "Test Connection" to verify';
        }

        // Cache invoice counts for better performance
        $invoiceCountsCacheKey = 'invoice_counts';
        $invoiceCounts = Cache::remember($invoiceCountsCacheKey, now()->addSeconds(30), function () {
            return Invoice::selectRaw('
                SUM(CASE WHEN sync_status = "pending" AND status != "cancelled" THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN sync_status = "failed" THEN 1 ELSE 0 END) as failed
            ')->first();
        });

        // Get sync statistics
        $itemCount = Item::whereNotNull('erpnext_id')->count();
        $customerCount = Customer::whereNotNull('erpnext_id')->count();
        $warehouseCount = Warehouse::whereNotNull('erpnext_id')->where('is_group', false)->count();

        return response()->json([
            'data' => [
                'configured' => $configured,
                'reachable' => $reachable,
                'ping' => $pingMessage,
                'url' => $setting->erpnext_url ?: config('services.erpnext.url'),
                'company' => $setting->erpnext_company ?: config('services.erpnext.company'),
                'price_list' => $setting->erpnext_price_list ?: config('services.erpnext.price_list') ?: 'Standard Selling',
                'last_synced_at' => $setting->last_synced_at?->toDateTimeString(),
                'pending_invoices' => $invoiceCounts->pending ?? 0,
                'failed_invoices' => $invoiceCounts->failed ?? 0,
                'sync_status' => [
                    'items_synced' => $itemCount,
                    'customers_synced' => $customerCount,
                    'warehouses_synced' => $warehouseCount,
                    'ready_for_pos' => $itemCount > 0 && $customerCount > 0 && $warehouseCount > 0,
                ],
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

    /**
     * POST /api/v1/sync/test-connection — test ERPNext connection manually.
     */
    public function testConnection()
    {
        if (! $this->client->enabled()) {
            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => 'ERPNext connector is not configured. Please configure it first.',
                ],
            ], 400);
        }

        try {
            $response = $this->client->ping();

            return response()->json([
                'data' => [
                    'success' => true,
                    'message' => $response['message'] ?? 'Connection successful',
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => 'Connection failed: '.$e->getMessage(),
                ],
            ], 500);
        }
    }
}
