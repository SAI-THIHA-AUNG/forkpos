@extends('layouts.app')
@section('title', 'Users')

@section('content')
<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-bold text-slate-800 mb-1">Users</h1>
    <p class="text-sm text-slate-500 mb-4">Cashiers imported from ERPNext's "Applicable for Users" have no PIN yet — set one so they can sign in. A PIN must be unique to one person.</p>

    @include('pos.partials.flash')
    @if ($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 divide-y divide-slate-100">
        @foreach ($users as $u)
        <form method="POST" action="{{ route('users.update', $u) }}" class="flex flex-wrap items-center gap-3 p-4">
            @csrf @method('PUT')
            <div class="min-w-48 flex-1">
                <p class="font-medium text-slate-800">{{ $u->name }}</p>
                <p class="text-xs text-slate-500">{{ $u->email }} @if($u->erpnext_id)· from ERPNext @endif @if(! $u->pos_pin)· <span class="text-amber-600">no PIN</span>@endif</p>
            </div>
            <select name="role" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                @foreach (['cashier', 'manager', 'admin'] as $r)
                <option value="{{ $r }}" @selected($u->role === $r) @disabled($r === 'admin' && ! auth()->user()->isAdmin())>{{ ucfirst($r) }}</option>
                @endforeach
            </select>
            <input type="password" inputmode="numeric" name="pos_pin" maxlength="8" placeholder="{{ $u->pos_pin ? 'new PIN (blank = keep)' : 'set PIN' }}" class="w-40 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            <label class="flex items-center gap-1 text-sm text-slate-600">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked($u->is_active) class="rounded border-slate-300"> Active
            </label>
            <button class="rounded-lg bg-slate-800 hover:bg-slate-900 text-white text-sm px-3 py-1.5">Save</button>
        </form>
        @endforeach
    </div>

    <h2 class="text-sm font-medium text-slate-500 uppercase tracking-wide mt-8 mb-2">Add a local user</h2>
    <form method="POST" action="{{ route('users.store') }}" class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
        @csrf
        <input name="name" required placeholder="Name" class="rounded-lg border border-slate-300 px-3 py-2">
        <input type="email" name="email" required placeholder="Email" class="rounded-lg border border-slate-300 px-3 py-2">
        <input type="password" name="password" required minlength="8" placeholder="Password (min 8)" class="rounded-lg border border-slate-300 px-3 py-2">
        <input type="password" inputmode="numeric" name="pos_pin" maxlength="8" placeholder="PIN (optional, 4–8 digits)" class="rounded-lg border border-slate-300 px-3 py-2">
        <select name="role" class="rounded-lg border border-slate-300 px-3 py-2">
            <option value="cashier">Cashier</option><option value="manager">Manager</option>
            @if(auth()->user()->isAdmin())<option value="admin">Admin</option>@endif
        </select>
        <button class="rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-medium px-4 py-2">Create user</button>
    </form>
</div>
@endsection
