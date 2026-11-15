<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * GET /api/v1/customers?q=
     */
    public function index(Request $request)
    {
        $pageLength = min((int) $request->query('limit_page_length', 50), 500);
        $offset = max(0, (int) $request->query('limit_start', 0));

        $query = Customer::with('group')->orderBy('customer_name');
        if ($q = $request->query('q')) {
            $query->search($q);
        }
        if ($request->boolean('walk_in_only')) {
            $query->where('is_walk_in', true);
        }

        $total = (clone $query)->toBase()->count();
        $customers = $query->offset($offset)->limit($pageLength)->get();

        return response()->json([
            'data' => $customers->map(fn (Customer $c) => $this->payload($c)),
            'meta' => ['total' => $total, 'limit_start' => $offset, 'limit_page_length' => $pageLength],
        ]);
    }

    /**
     * POST /api/v1/customers — quick-add from the POS screen.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:191'],
            'customer_group_id' => ['nullable', 'exists:customer_groups,id'],
            'territory_id' => ['nullable', 'exists:territories,id'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['customer_code'] = $this->nextCode();
        $customer = Customer::create($data);

        return response()->json(['data' => $this->payload($customer->load('group'))], 201);
    }

    /**
     * GET /api/v1/customers/{id}
     */
    public function show(int $id)
    {
        $customer = Customer::with(['group', 'territory'])->findOrFail($id);

        $stats = [
            'invoice_count' => $customer->invoices()->where('status', '!=', 'cancelled')->count(),
            'total_spent' => (float) $customer->invoices()
                ->where('status', '!=', 'cancelled')
                ->where('is_return', false)
                ->sum('grand_total'),
        ];

        return response()->json(['data' => array_merge($this->payload($customer), $stats)]);
    }

    /**
     * PUT /api/v1/customers/{id}
     */
    public function update(Request $request, int $id)
    {
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'customer_name' => ['sometimes', 'string', 'max:191'],
            'customer_group_id' => ['nullable', 'exists:customer_groups,id'],
            'territory_id' => ['nullable', 'exists:territories,id'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
            'loyalty_points' => ['sometimes', 'integer', 'min:0'],
        ]);

        $customer->update($data);

        return response()->json(['data' => $this->payload($customer->fresh(['group']))]);
    }

    protected function payload(Customer $c): array
    {
        return [
            'id' => $c->id,
            'customer_code' => $c->customer_code,
            'customer_name' => $c->customer_name,
            'customer_group' => $c->group?->name,
            'customer_group_id' => $c->customer_group_id,
            'email' => $c->email,
            'phone' => $c->phone,
            'address_line1' => $c->address_line1,
            'city' => $c->city,
            'country' => $c->country,
            'loyalty_points' => (int) $c->loyalty_points,
            'is_walk_in' => (bool) $c->is_walk_in,
            'erpnext_id' => $c->erpnext_id,
        ];
    }

    protected function nextCode(): string
    {
        // Only consider locally-generated codes so ERPNext-synced names cannot
        // break the sequence.
        $max = Customer::where('customer_code', 'like', 'CUST-%')->max('customer_code');
        $seq = $max ? (int) substr($max, 5) + 1 : 1;

        return 'CUST-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
