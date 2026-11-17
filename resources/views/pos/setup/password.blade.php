@extends('layouts.app')
@section('title', 'Set a new password')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-900 to-slate-700 p-6">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl p-8">
        <p class="text-xs font-semibold text-brand-600 uppercase tracking-wide">Setup · step 1 of 3</p>
        <h1 class="text-xl font-bold text-slate-800 mt-1 mb-1">Set a new password</h1>
        <p class="text-sm text-slate-500 mb-6">You signed in with the default account. Choose your own password before continuing.</p>

        @include('pos.partials.flash')
        @if ($errors->any())
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
            @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
        </div>
        @endif

        <form method="POST" action="{{ route('setup.password.update') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Current (default) password</label>
                <input type="password" name="current_password" required autofocus class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">New password</label>
                <input type="password" name="password" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Confirm new password</label>
                <input type="password" name="password_confirmation" required minlength="8" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <button class="w-full rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-medium py-2.5">Save and continue</button>
        </form>
    </div>
</div>
@endsection
