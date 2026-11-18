<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The one-off repair for invoices an older ForkPOS left as drafts in ERPNext.
 * Only documents whose remarks say ForkPOS created them may be touched.
 */
class SubmitDraftsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::current()->forceFill([
            'erpnext_url' => 'https://erp.example.test',
            'erpnext_api_key' => 'fake-key',
            'erpnext_api_secret' => 'fake-secret',
            'erpnext_company' => 'ACME',
        ])->save();
        Setting::flushCache();
    }

    /** @return array<int, array<string, mixed>> */
    protected function drafts(): array
    {
        return [
            ['name' => 'ACC-SINV-TVP-2026-00001', 'grand_total' => 3000, 'posting_date' => '2026-11-02', 'remarks' => 'Synced from forkpos Laravel POS (ACC-SINV-TVP-2026-00001)'],
            ['name' => 'ACC-SINV-TVP-2026-00002', 'grand_total' => 1500, 'posting_date' => '2026-11-10', 'remarks' => 'Synced from forkpos Laravel POS (ACC-SINV-TVP-2026-00002)'],
        ];
    }

    public function test_dry_run_lists_the_drafts_and_submits_nothing(): void
    {
        Http::fake(function ($request) {
            $url = urldecode($request->url());
            if (str_contains($url, '/api/resource/Sales Invoice')) {
                return Http::response(['data' => $this->drafts()]);
            }

            return Http::response(['data' => []]);
        });

        $this->artisan('pos:submit-drafts')
            ->expectsOutputToContain('2 draft(s)')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains(urldecode($request->url()), 'frappe.client.submit'));

        // The list must already be scoped to ForkPOS-created drafts, not every draft,
        // and ordered by posting date so stock-dependent submits go oldest first.
        Http::assertSent(function ($request) {
            $url = urldecode($request->url());

            return str_contains($url, '/api/resource/Sales Invoice')
                && str_contains($url, 'Synced from forkpos Laravel POS')
                && str_contains($url, 'posting_date asc');
        });
    }

    public function test_apply_submits_each_draft_and_reports_what_failed(): void
    {
        Http::fake(function ($request) {
            $url = urldecode($request->url());

            if (str_contains($url, 'frappe.client.submit')) {
                $doc = $request->data()['doc'] ?? [];
                $name = is_array($doc) ? ($doc['name'] ?? '') : '';

                return str_contains($name, 'BAD')
                    ? Http::response(['exc_type' => 'ValidationError', '_server_messages' => json_encode([json_encode(['message' => 'Insufficient stock'])])], 417)
                    : Http::response(['message' => ['docstatus' => 1]]);
            }
            if (preg_match('#/api/resource/Sales Invoice/([^/?]+)#', $url, $m)) {
                return Http::response(['data' => [
                    'name' => $m[1], 'docstatus' => 0, 'grand_total' => 10,
                    'remarks' => 'Synced from forkpos Laravel POS ('.$m[1].')',
                ]]);
            }
            if (str_contains($url, '/api/resource/Sales Invoice')) {
                return Http::response(['data' => [
                    ['name' => 'ACC-SINV-TVP-2026-00001', 'grand_total' => 3000, 'remarks' => 'Synced from forkpos Laravel POS (1)'],
                    ['name' => 'ACC-SINV-TVP-BAD', 'grand_total' => 1500, 'remarks' => 'Synced from forkpos Laravel POS (2)'],
                ]]);
            }

            return Http::response(['data' => []]);
        });

        $this->artisan('pos:submit-drafts', ['--apply' => true])
            ->expectsOutputToContain('Submitted 1, failed 1')
            ->assertExitCode(1);

        foreach (['ACC-SINV-TVP-2026-00001', 'ACC-SINV-TVP-BAD'] as $name) {
            Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'frappe.client.submit')
                && str_contains(json_encode($request->data()), $name));
        }
    }
}
