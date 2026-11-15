@extends('layouts.app')
@section('title', 'Select POS Profile')

@section('content')
<div class="min-h-screen bg-slate-100 py-10 px-6">
    <div class="max-w-5xl mx-auto">
        <div class="flex items-end justify-between mb-8">
            <div>
                <p class="text-sm text-slate-500">Welcome back,</p>
                <h1 class="text-2xl font-bold text-slate-800">{{ auth()->user()->name }}</h1>
            </div>
            <form method="POST" action="{{ route('logout') }}">@csrf <button class="text-sm text-slate-500 hover:text-slate-700">Sign out</button></form>
        </div>

        @include('pos.partials.flash')

        @if ($currentShift)
        <div class="mb-6 p-4 rounded-xl bg-blue-50 border border-blue-200 flex items-center justify-between">
            <div>
                <p class="text-sm text-blue-700">You have an open shift on <strong>{{ $currentShift->posProfile?->name }}</strong>.</p>                <p class="text-xs text-blue-600 mt-0.5">Started {{ $currentShift->started_at?->diffForHumans() }} · shift {{ $currentShift->shift_no }}</p>
            </div>
            <form method="POST" action="{{ route('profile.select') }}">@csrf
                <input type="hidden" name="pos_profile_id" value="{{ $currentShift->pos_profile_id }}">
                <button class="rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2">Resume shift →</button>
            </form>
        </div>
        @endif

        <h2 class="text-sm font-medium text-slate-500 uppercase tracking-wide mb-3">Your POS Profiles</h2>

        @if ($profiles->isEmpty())
        <div class="p-8 rounded-xl bg-white border border-slate-200 text-center">
            <p class="text-slate-500">No POS profiles are assigned to you.</p>
            <p class="text-sm text-slate-400 mt-2">Ask a manager to attach you to a profile, or seed the demo data with <code>php artisan migrate:fresh --seed</code>.</p>
        </div>
        @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($profiles as $p)
            <form method="POST" action="{{ route('profile.select') }}" class="block">
                @csrf
                <input type="hidden" name="pos_profile_id" value="{{ $p->id }}">
                <button type="submit" class="w-full text-left p-5 rounded-xl bg-white border border-slate-200 hover:border-brand-500 hover:shadow-md transition group">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <p class="font-semibold text-slate-800 group-hover:text-brand-700">{{ $p->name }}</p>
                            <p class="text-xs text-slate-500">{{ $p->company }} · {{ $p->currency }}</p>
                        </div>
                        @if($p->is_default)<span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 font-medium uppercase">Default</span>@endif
                    </div>
                    <div class="space-y-1 text-xs text-slate-500">
                        <p>📍 {{ $p->warehouse?->name ?? '—' }}</p>
                        <p>💵 {{ $p->payments->pluck('mode_of_payment')->join(', ') }}</p>
                        <p>{{ $p->tax_inclusive ? 'Tax-inclusive prices' : 'Tax added on top' }} · {{ $p->require_shift ? 'Shift required' : 'No shift required' }}</p>
                    </div>
                </button>
            </form>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endsection
