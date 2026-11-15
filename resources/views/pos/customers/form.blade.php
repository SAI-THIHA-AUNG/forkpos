@extends('layouts.app')
@section('title', $customer->exists ? 'Edit customer' : 'New customer')

@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-bold text-slate-800 mb-4">{{ $customer->exists ? 'Edit customer' : 'New customer' }}</h1>
    @include('pos.partials.flash')

    <form method="POST" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}" class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
        @csrf
        @if ($customer->exists) @method('PUT') @endif

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Name *</label>
            <input type="text" name="customer_name" value="{{ old('customer_name', $customer->customer_name) }}" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Group</label>
                <select name="customer_group_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 bg-white">
                    <option value="">—</option>
                    @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected(old('customer_group_id', $customer->customer_group_id) == $g->id)>{{ $g->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Territory</label>
                <select name="territory_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 bg-white">
                    <option value="">—</option>
                    @foreach ($territories as $t)
                    <option value="{{ $t->id }}" @selected(old('territory_id', $customer->territory_id) == $t->id)>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Phone</label>
                <input type="text" name="phone" value="{{ old('phone', $customer->phone) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email', $customer->email) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Address</label>
            <input type="text" name="address_line1" value="{{ old('address_line1', $customer->address_line1) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">City</label>
                <input type="text" name="city" value="{{ old('city', $customer->city) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Country</label>
                <input type="text" name="country" value="{{ old('country', $customer->country) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea name="notes" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('notes', $customer->notes) }}</textarea>
        </div>

        @if ($customer->exists && $customer->loyalty_points > 0)
        <p class="text-xs text-slate-500">Loyalty balance: <strong>{{ $customer->loyalty_points }} pts</strong> — only managers can adjust this via the API.</p>
        @endif

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('customers.index') }}" class="px-4 py-2 text-slate-600 hover:text-slate-800">Cancel</a>
            <button class="px-5 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-medium">{{ $customer->exists ? 'Save' : 'Create' }}</button>
        </div>
    </form>
</div>
@endsection
