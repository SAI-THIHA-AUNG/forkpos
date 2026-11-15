<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Territory;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /** GET /customers?q= */
    public function index(Request $request)
    {
        $customers = Customer::with('group', 'territory')
            ->orderBy('customer_name')
            ->when($q = $request->query('q'), fn ($query) => $query->search($q))
            ->when($request->boolean('walk_in_only'), fn ($query) => $query->where('is_walk_in', true))
            ->paginate(25);

        // The register's customer picker fetches this route as JSON (live
        // type-to-search). Return a compact payload for AJAX requests.
        if ($request->wantsJson()) {
            return response()->json([
                'data' => $customers->map(fn (Customer $c) => [
                    'id' => $c->id,
                    'customer_code' => $c->customer_code,
                    'customer_name' => $c->customer_name,
                    'phone' => $c->phone,
                    'territory' => $c->territory?->name,
                ])->values(),
            ]);
        }

        return view('pos.customers.index', ['customers' => $customers, 'q' => $q]);
    }

    public function create()
    {
        $groups = CustomerGroup::orderBy('name')->get();
        $territories = Territory::orderBy('name')->get();

        return view('pos.customers.form', [
            'customer' => new Customer(),
            'groups' => $groups,
            'territories' => $territories,
        ]);
    }

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

        return redirect()->route('customers.show', $customer)->with('success', "Customer {$customer->customer_name} created.");
    }

    public function show(Customer $customer)
    {
        $customer->load('group', 'territory');
        $invoices = $customer->invoices()
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        $totalSpent = (float) $customer->invoices()
            ->where('status', '!=', 'cancelled')
            ->where('is_return', false)
            ->sum('grand_total');

        return view('pos.customers.show', [
            'customer' => $customer,
            'invoices' => $invoices,
            'totalSpent' => $totalSpent,
        ]);
    }

    public function edit(Customer $customer)
    {
        $groups = CustomerGroup::orderBy('name')->get();
        $territories = Territory::orderBy('name')->get();

        return view('pos.customers.form', [
            'customer' => $customer,
            'groups' => $groups,
            'territories' => $territories,
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
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
        ]);

        // SECURITY: loyalty_points is deliberately NOT accepted here.
        // Minting loyalty points is a manager action via the API only.
        $customer->update($data);

        return redirect()->route('customers.show', $customer)->with('success', 'Customer updated.');
    }

    private function nextCode(): string
    {
        $max = Customer::where('customer_code', 'like', 'CUST-%')->max('customer_code');
        $seq = $max ? (int) substr($max, 5) + 1 : 1;

        return 'CUST-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
