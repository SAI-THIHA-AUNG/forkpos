@extends('layouts.app')
@section('title', 'Sign in')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-900 to-slate-700 p-6">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-brand-600 text-white font-bold text-2xl mb-4">F</div>
            <h1 class="text-2xl font-bold text-white">ForkPOS</h1>
            <p class="text-slate-400 text-sm mt-1">{{ config('app.name') }} · POS web console</p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-8">
            @if($needsBootstrap)
            <div class="mb-6 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
                No users exist yet. Run <code class="bg-amber-100 px-1 rounded">php artisan migrate --seed</code> to create the bootstrap administrator.
            </div>
            @elseif($bootstrapPending ?? false)
            <div class="mb-6 p-3 rounded-lg bg-blue-50 border border-blue-200 text-blue-800 text-sm">
                First run? Sign in with the bootstrap administrator account (<code>{{ config('forkpos.bootstrap_admin.email') }}</code>) — you will be asked to set a new password.
            </div>
            @endif

            @if ($errors->any())
            <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
            </div>
            @endif

            @php($pinTab = $errors->has('pin'))
            <ul class="flex border-b border-slate-200 mb-6 text-sm">
                <li><button type="button" data-tab="email" class="tab-btn px-4 py-2 font-medium border-b-2 {{ $pinTab ? 'border-transparent text-slate-500 hover:text-slate-700' : 'border-brand-600 text-brand-700' }}">Email &amp; password</button></li>
                <li><button type="button" data-tab="pin" class="tab-btn px-4 py-2 font-medium border-b-2 {{ $pinTab ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">PIN</button></li>
            </ul>

            <form method="POST" action="{{ route('login') }}">
                @csrf
                {{-- Only the active pane's inputs are enabled: the login form treats a
                     supplied PIN as PIN login, so a leftover PIN value must never be
                     submitted next to an email/password pair. --}}
                <div data-pane="email" class="space-y-4 {{ $pinTab ? 'hidden' : '' }}">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" autofocus @disabled($pinTab) class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:ring-brand-500 outline-none" placeholder="email">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                        <input type="password" name="password" @disabled($pinTab) class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:ring-brand-500 outline-none" placeholder="••••••••">
                    </div>
                </div>

                <div data-pane="pin" class="space-y-4 {{ $pinTab ? '' : 'hidden' }}">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">PIN (4–8 digits)</label>
                        <input type="password" inputmode="numeric" pattern="[0-9]*" name="pin" maxlength="8" @disabled(! $pinTab) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-center text-2xl tracking-[0.5em] focus:border-brand-500 focus:ring-brand-500 outline-none" placeholder="••••">
                        <p class="text-xs text-slate-500 mt-2">PIN login matches against all active users — set a unique PIN per cashier.</p>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600 mt-4">
                    <input type="checkbox" name="remember" class="rounded border-slate-300">
                    Keep me signed in on this terminal
                </label>

                <button type="submit" class="mt-6 w-full rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-medium py-2.5 transition">Sign in</button>
            </form>
        </div>

        <p class="text-center text-slate-500 text-xs mt-6">ForkPOS v0.1.0 · GPL-3.0</p>
    </div>
</div>

<script>
    const panes = document.querySelectorAll('[data-pane]');

    // Disabled inputs are not submitted, so only the visible tab reaches the server.
    const syncPaneInputs = () => {
        panes.forEach(pane => {
            const active = !pane.classList.contains('hidden');
            pane.querySelectorAll('input').forEach(input => { input.disabled = !active; });
        });
    };

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const tab = btn.dataset.tab;
            document.querySelectorAll('.tab-btn').forEach(b => {
                const active = b === btn;
                b.classList.toggle('border-brand-600', active);
                b.classList.toggle('text-brand-700', active);
                b.classList.toggle('border-transparent', !active);
                b.classList.toggle('text-slate-500', !active);
            });
            panes.forEach(pane => pane.classList.toggle('hidden', pane.dataset.pane !== tab));
            syncPaneInputs();
            document.querySelector(`[data-pane="${tab}"] input:not([disabled])`)?.focus();
        });
    });

    syncPaneInputs();
</script>
@endsection
