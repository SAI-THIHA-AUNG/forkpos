@extends('layouts.app')
@section('title', 'My PIN')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-slate-100 p-6">
    <div class="w-full max-w-md bg-white rounded-2xl shadow p-8">
        <h1 class="text-xl font-bold text-slate-900">{{ $hasPin ? 'Change my PIN' : 'Choose a PIN' }}</h1>
        <p class="text-sm text-slate-500 mt-1 mb-6">
            Next time, pick your name on the login screen and enter this PIN. It only works for you.
            @if(auth()->user()->erpnext_id)
                Your ERPNext password is asked again every {{ config('forkpos.erpnext_reverify_days') }} days.
            @endif
        </p>

        @if (session('status'))<div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm">{{ session('status') }}</div>@endif
        @if ($errors->any())
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
            @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
        </div>
        @endif

        <form method="POST" action="{{ route('account.pin.update') }}" class="space-y-4">
            @csrf @method('PUT')
            @if($needsCurrentPin)
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Current PIN</label>
                <input type="password" inputmode="numeric" name="current_pin" maxlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            @endif
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">New PIN (4–8 digits)</label>
                <input type="password" inputmode="numeric" pattern="[0-9]*" name="pin" maxlength="8" autofocus class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Repeat PIN</label>
                <input type="password" inputmode="numeric" pattern="[0-9]*" name="pin_confirmation" maxlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <button type="submit" class="w-full rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-medium py-2.5">Save PIN</button>
        </form>
    </div>
</div>
@endsection
