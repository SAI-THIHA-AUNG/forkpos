@extends('layouts.app')
@section('title', 'Connect ERPNext')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-900 to-slate-700 p-6">
    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl p-8">
        <p class="text-xs font-semibold text-brand-600 uppercase tracking-wide">Setup · step 2 of 3</p>
        <h1 class="text-xl font-bold text-slate-800 mt-1 mb-1">Connect to ERPNext</h1>
        <p class="text-sm text-slate-500 mb-6">Enter your server URL and an API key/secret (ERPNext → User → API Access). They are checked against the server before being saved; the secret is stored encrypted.</p>

        @include('pos.partials.flash')
        @if ($errors->any())
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
            @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
        </div>
        @endif

        <form method="POST" action="{{ route('setup.connect.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Server URL</label>
                <input type="url" name="erpnext_url" required autofocus value="{{ old('erpnext_url', $setting->erpnext_url) }}" placeholder="https://your-site.erpnext.com" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">API key</label>
                <input type="text" name="erpnext_api_key" required value="{{ old('erpnext_api_key', $setting->erpnext_api_key) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">API secret</label>
                <input type="password" name="erpnext_api_secret" {{ $setting->erpnext_api_secret ? '' : 'required' }} placeholder="{{ $setting->erpnext_api_secret ? '•••• saved — leave blank to keep' : '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <button class="w-full rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-medium py-2.5">Test connection and continue</button>
        </form>
    </div>
</div>
@endsection
