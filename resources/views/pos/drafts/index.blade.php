@extends('layouts.app')
@section('title', 'Drafts')

@php
    $currency = optional(request()->attributes->get('posProfile'))->effectiveCurrencySymbol() ?? '';
@endphp

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Drafts</h1>
            <p class="text-sm text-slate-500">Saved carts for this POS profile. Nothing is charged and stock does not change until you charge a draft.</p>
        </div>
        <a href="{{ route('pos.register') }}" class="rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2">New sale</a>
    </div>
    @include('pos.partials.flash')

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100 bg-slate-50">
                <th class="text-left px-4 py-2">Draft</th><th class="text-left px-4 py-2">Customer</th><th class="text-center px-4 py-2">Items</th><th class="text-right px-4 py-2">Total</th><th class="text-left px-4 py-2">Saved</th><th></th>
            </tr></thead>
            <tbody>
                @forelse ($drafts as $draft)
                <tr class="border-b border-slate-50 hover:bg-slate-50">
                    <td class="px-4 py-2 font-medium">{{ $draft->label }}@if(! empty($draft->payload['is_credit']))<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">CREDIT</span>@endif</td>
                    <td class="px-4 py-2 text-slate-600">{{ $draft->customer?->customer_name ?? 'Walk-in' }}</td>
                    <td class="px-4 py-2 text-center">{{ $draft->item_count }}</td>
                    <td class="px-4 py-2 text-right">{{ $currency }}{{ \App\Support\Money::format($draft->total, 2) }}</td>
                    <td class="px-4 py-2 text-xs text-slate-500">{{ $draft->updated_at?->format('M j, H:i') }} · {{ $draft->user?->name }}</td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        <a href="{{ route('pos.register', ['draft' => $draft->id]) }}" class="rounded bg-brand-600 hover:bg-brand-700 text-white text-xs font-medium px-3 py-1.5">Resume</a>
                        <form method="POST" action="{{ route('drafts.destroy', $draft) }}" class="inline" onsubmit="return confirm('Delete this draft?')">
                            @csrf @method('DELETE')
                            <button class="ml-2 text-xs text-red-600 hover:underline">Delete</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">No drafts. Use “Save draft” on the register to keep a cart for later.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
