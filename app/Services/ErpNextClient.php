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
 * Credentials come ONLY from the settings table (first-run setup page or
 * PUT /api/v1/settings) — there is no .env / config fallback.
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

        $this->url = rtrim((string) $this->setting->erpnext_url, '/');
        $this->apiKey = (string) $this->setting->erpnext_api_key;
        $this->apiSecret = (string) $this->setting->erpnext_api_secret;
    }

    /**
     * Build a client from credentials that are NOT saved yet (setup page
     * "Test connection"). The secret goes through the same encrypted cast.
     */
    public static function make(string $url, string $key, string $secret): self
    {
        return new self(new Setting([
            'erpnext_url' => $url,
            'erpnext_api_key' => $key,
            'erpnext_api_secret' => $secret,
        ]));
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
     * GET /api/method/frappe.auth.get_logged_user — proves the key/secret are VALID.
     *
     * `ping` is open to guests in Frappe, so it only proves the host is up; a
     * wrong key/secret still "pings". This call is authenticated and returns
     * the ERPNext user the token belongs to.
     *
     * @return array{user: string}
     */
    public function verify(): array
    {
        $response = $this->request('get', '/api/method/frappe.auth.get_logged_user');
        $user = $response['message'] ?? null;
        if (! is_string($user) || $user === '' || $user === 'Guest') {
            throw new RuntimeException('ERPNext did not accept the API key/secret (resolved to Guest).');
        }

        return ['user' => $user];
    }

    /**
     * Fetch EVERY row of a doctype, page by page (stops on the first short page).
     *
     * @param  int  $pageSize  rows per request
     * @param  int  $maxRows  safety cap so a bad filter can't loop forever
     */
    public function listAll(string $doctype, array $fields = ['name'], array $filters = [], int $pageSize = 500, ?string $orderBy = null, int $maxRows = 100000): array
    {
        $pageSize = max(1, $pageSize);
        $all = [];
        $offset = 0;
        while (count($all) < $maxRows) {
            $page = $this->list($doctype, $fields, $filters, $pageSize, $offset, $orderBy);
            $all = array_merge($all, $page);
            if (count($page) < $pageSize) {
                break;
            }
            $offset += $pageSize;
        }

        return $all;
    }

    /**
     * GET /api/method/ping — host reachability only (does NOT validate credentials).
     */
    public function ping(): array
    {
        $response = $this->request('get', '/api/method/ping');

        return $response;
    }

    public function company(): string
    {
        return (string) $this->setting->erpnext_company;
    }

    /** Legacy global price list (only used by the manual full sync when no profile context exists). */
    public function priceList(): string
    {
        return (string) $this->setting->erpnext_price_list;
    }

    protected function request(string $method, string $path, ?array $query = null, ?array $json = null): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('ERPNext connector is not configured. Complete the setup step (URL, API key, API secret).');
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
