<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PosDraft;
use App\Models\PosProfile;
use Illuminate\Http\Request;

/**
 * Saved register carts. Every cashier on the POS profile can see, resume and
 * delete the profile's drafts; a draft never touches stock or payments.
 */
class DraftController extends Controller
{
    /** GET /pos/drafts */
    public function index(Request $request)
    {
        /** @var PosProfile $profile */
        $profile = $request->attributes->get('posProfile');

        return view('pos.drafts.index', [
            'drafts' => PosDraft::with(['user', 'customer'])
                ->where('pos_profile_id', $profile->id)
                ->orderByDesc('updated_at')
                ->get(),
        ]);
    }

    /** POST /pos/drafts — save the register cart (creates, or updates when draft_id is sent). */
    public function store(Request $request)
    {
        /** @var PosProfile $profile */
        $profile = $request->attributes->get('posProfile');

        $data = $request->validate([
            'draft_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'total' => ['nullable', 'numeric'],
            'coupon_code' => ['nullable', 'string', 'max:32'],
            'additional_discount_type' => ['nullable', 'in:percentage,amount'],
            'additional_discount_value' => ['nullable', 'numeric', 'min:0'],
            'is_credit' => ['nullable', 'boolean'],
            'credit_days' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty' => ['required', 'numeric', 'not_in:0'],
            'items.*.rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'in:percentage,amount'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $customer = ! empty($data['customer_id']) ? Customer::find($data['customer_id']) : null;
        $payload = collect($data)->only([
            'coupon_code', 'additional_discount_type', 'additional_discount_value',
            'is_credit', 'credit_days', 'due_date', 'items',
        ])->all();

        $attributes = [
            'customer_id' => $customer?->id,
            'label' => trim((string) ($data['label'] ?? '')) ?: ($customer?->customer_name ?? 'Draft '.now()->format('H:i')),
            'item_count' => count($data['items']),
            'total' => (float) ($data['total'] ?? 0),
            'payload' => $payload,
        ];

        $draft = ! empty($data['draft_id'])
            ? PosDraft::where('pos_profile_id', $profile->id)->find($data['draft_id'])
            : null;

        if ($draft) {
            $draft->update($attributes);
        } else {
            $draft = PosDraft::create($attributes + ['pos_profile_id' => $profile->id, 'user_id' => $request->user()->id]);
        }

        session()->flash('success', "Draft “{$draft->label}” saved. Nothing was charged and stock is unchanged.");

        return response()->json(['data' => ['draft_id' => $draft->id, 'redirect' => route('drafts.index')]], 201);
    }

    /** DELETE /pos/drafts/{draft} */
    public function destroy(Request $request, PosDraft $draft)
    {
        abort_unless($draft->pos_profile_id === $request->attributes->get('posProfile')->id, 404);
        $draft->delete();

        return back()->with('success', 'Draft deleted.');
    }
}
