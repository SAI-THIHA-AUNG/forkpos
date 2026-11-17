@extends('layouts.app')
@section('title', 'Choose POS profiles')

@section('content')
<div class="min-h-screen bg-slate-100 py-10 px-6">
    <div class="max-w-3xl mx-auto bg-white rounded-2xl border border-slate-200 p-8">
        <p class="text-xs font-semibold text-brand-600 uppercase tracking-wide">Setup · step 3 of 3</p>
        <h1 class="text-xl font-bold text-slate-800 mt-1 mb-1">Which POS profiles does this terminal serve?</h1>
        <p class="text-sm text-slate-500 mb-6">Each profile brings its own currency, warehouse, price list, payment methods and cashiers from ERPNext. Items, prices, stock and customers are pulled for a profile when a shift opens on it.</p>

        @include('pos.partials.flash')

        @if ($error)
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">Could not list POS profiles: {{ $error }}</div>
        @endif
        @if ($errors->any())
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
            @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
        </div>
        @endif

        @if (! $error && empty($remote))
        <p class="text-sm text-slate-500">ERPNext has no enabled POS Profile visible to this API user. Create one in ERPNext (Accounts → POS Profile), then reload.</p>
        @elseif (! $error)
        <form method="POST" action="{{ route('setup.profiles.store') }}" class="space-y-3">
            @csrf
            @foreach ($remote as $p)
            <label class="flex items-start gap-3 p-4 rounded-xl border border-slate-200 hover:border-brand-500 cursor-pointer">
                <input type="checkbox" name="profiles[]" value="{{ $p['name'] }}" class="mt-1 rounded border-slate-300"
                       @checked(in_array($p['name'], $enabled, true) || count($remote) === 1)>
                <span>
                    <span class="block font-semibold text-slate-800">{{ $p['name'] }}</span>
                    <span class="block text-xs text-slate-500">{{ $p['company'] ?? '—' }} · {{ $p['currency'] ?? '—' }} · {{ $p['warehouse'] ?? 'no warehouse' }}</span>
                </span>
            </label>
            @endforeach
            <button class="mt-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-medium px-5 py-2.5">Use selected profiles</button>
        </form>
        @endif

        <div class="mt-6 text-sm"><a href="{{ route('setup.connect') }}" class="text-slate-500 hover:text-slate-700">← Change ERPNext connection</a></div>
    </div>
</div>
@endsection
