<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP client for the Frappe/ERPNext REST API (/api/resource/*).
 *
 * Auth follows the Frappe token convention:
 *   Authorization: token <api_key>:<api_secret>
 *
 * Configure via the settings table (GET/PUT /api/v1/settings) or env fallbacks:
 *   ERPNEXT_URL, ERPNEXT_API_KEY, ERPNEXT_API_SECRET, ERPNEXT_COMPANY
 */
class ErpNextClient
{
    protected Setting $setting;

    protected string $url;

    protected string $apiKey;

    protected string $apiSecret;

    public function __construct(?Setting $setting = null)
    {
        $this->setting = $setting ?? Setting::current();

        // Settings table first, .env fallback second (never write env values back
        // to the model — the api_secret column is encrypted-cast).
        $this->url = rtrim((string) ($this->setting->erpnext_url ?: config('services.erpnext.url')), '/');
        $this->apiKey = (string) ($this->setting->erpnext_api_key ?: config('services.erpnext.api_key'));
        $this->apiSecret = (string) ($this->setting->erpnext_api_secret ?: config('services.erpnext.api_secret'));
    }

    public function enabled(): bool
    {
        return $this->url !== '' && $this->apiKey !== '' && $this->apiSecret !== '';
    }

    public function baseUrl(): string
    {
        return $this->url;
    }

    public function tokenHeader(): array
    {
        return [
            'Authorization' => 'token '.$this->apiKey.':'.$this->apiSecret,
            'Accept' => 'application/json',
        ];
    }

    /**
     * GET /api/resource/{doctype} (list).
     */
    public function list(string $doctype, array $fields = ['name'], array $filters = [], int $limit = 500, int $offset = 0, ?string $orderBy = null): array
    {
        $query = array_filter([
            'fields' => json_encode($fields),
            'filters' => $filters ? json_encode($filters) : null,
            'limit_page_length' => $limit,
            'limit_start' => $offset,
            'order_by' => $orderBy,
        ]);

        $response = $this->request('get', "/api/resource/{$doctype}", $query);

        return $response['data'] ?? [];
    }

    /**
     * GET /api/resource/{doctype}/{name} (single doc with child tables).
     */
    public function doc(string $doctype, string $name): array
    {
        $response = $this->request('get', "/api/resource/{$doctype}/".rawurlencode($name));

        return $response['data'] ?? [];
    }

    /**
     * POST /api/resource/{doctype} — create a document.
     */
    public function create(string $doctype, array $data): array
    {
        return $this->request('post', "/api/resource/{$doctype}", null, $data);
    }

    /**
     * PUT /api/resource/{doctype}/{name} — update a document.
     */
    public function update(string $doctype, string $name, array $data): array
    {
        return $this->request('put', "/api/resource/{$doctype}/".rawurlencode($name), null, $data);
    }

    /**
     * GET /api/method/ping — cheap connectivity probe.
     */
    public function ping(): array
    {
        $response = $this->request('get', '/api/method/ping');

        return $response;
    }

    public function company(): string
    {
        return (string) ($this->setting->erpnext_company ?: config('services.erpnext.company'));
    }

    public function priceList(): string
    {
        return (string) ($this->setting->erpnext_price_list ?: config('services.erpnext.price_list') ?: 'Standard Selling');
    }

    protected function request(string $method, string $path, ?array $query = null, ?array $json = null): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('ERPNext connector is not configured. Set erpnext_url / erpnext_api_key / erpnext_api_secret (API: PUT /api/v1/settings).');
        }

        try {
            $request = Http::withHeaders($this->tokenHeader())
                ->timeout(30)
                ->connectTimeout(10);

            $response = match (strtolower($method)) {
                'get' => $request->get($this->baseUrl().$path, $query ?? []),
                'post' => $request->post($this->baseUrl().$path, $json ?? []),
                'put' => $request->put($this->baseUrl().$path, $json ?? []),
                default => throw new RuntimeException("Unsupported HTTP method {$method}"),
            };
        } catch (ConnectionException $e) {
            Log::warning('ERPNext connection failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Cannot reach ERPNext at '.$this->baseUrl().': '.$e->getMessage());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('ERPNext rejected the API token (HTTP '.$response->status().'). Check erpnext_api_key / erpnext_api_secret.');
        }
        if ($response->serverError()) {
            throw new RuntimeException('ERPNext server error (HTTP '.$response->status().'): '.mb_substr($response->body(), 0, 500));
        }
        if ($response->failed()) {
            // Frappe validation errors come as 4xx with {"exc_type":..., "_server_messages": ...} or {"data": ...}
            $message = $response->json('message')
                ?? $response->json('exc_type')
                ?? mb_substr($response->body(), 0, 500);
            throw new RuntimeException("ERPNext rejected the request (HTTP {$response->status()}): {$message}");
        }

        return $response->json() ?? [];
    }
}
