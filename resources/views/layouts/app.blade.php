<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'ForkPOS') — {{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { 50:'#eef6ff',100:'#d9eaff',500:'#3b82f6',600:'#2563eb',700:'#1d4ed8' },
                    },
                    fontFamily: { sans: ['Inter','system-ui','sans-serif'] },
                },
            },
        };
    </script>
    <style>
        [x-cloak]{display:none}
        /* number inputs without spinners for cleaner POS look */
        input[type=number].no-spin::-webkit-inner-spin-button,
        input[type=number].no-spin::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number].no-spin { -moz-appearance:textfield; }
        @media print {
            .no-print { display: none !important; }
            .print-area { position: absolute; left: 0; top: 0; width: 100%; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased">
@auth
@if(session()->has('pos_profile_id'))
<div class="no-print">
    <aside id="sidebar" class="fixed inset-y-0 left-0 w-16 bg-slate-900 text-slate-300 flex flex-col items-center py-4 z-30 transition-all duration-200 overflow-hidden whitespace-nowrap">
        <a href="{{ route('pos.register') }}" class="w-10 h-10 rounded-lg bg-brand-600 text-white flex items-center justify-center font-bold text-lg mb-6 shrink-0" title="Register">F</a>
        <nav class="flex-1 flex flex-col gap-1">
            <a href="{{ route('pos.register') }}" class="w-10 h-10 rounded-md flex items-center justify-center hover:bg-slate-800 {{ request()->routeIs('pos.register') ? 'bg-slate-800 text-white' : '' }} shrink-0" title="Register">🛒</a>
            <a href="{{ route('shifts.current') }}" class="w-10 h-10 rounded-md flex items-center justify-center hover:bg-slate-800 {{ request()->routeIs('shifts.*') ? 'bg-slate-800 text-white' : '' }} shrink-0" title="Shifts">Shift</a>
            <a href="{{ route('invoices.index') }}" class="w-10 h-10 rounded-md flex items-center justify-center hover:bg-slate-800 {{ request()->routeIs('invoices.*') ? 'bg-slate-800 text-white' : '' }} shrink-0" title="Sales">List</a>
            <a href="{{ route('customers.index') }}" class="w-10 h-10 rounded-md flex items-center justify-center hover:bg-slate-800 {{ request()->routeIs('customers.*') ? 'bg-slate-800 text-white' : '' }} shrink-0" title="Customers">👤</a>
            <a href="{{ route('dashboard') }}" class="w-10 h-10 rounded-md flex items-center justify-center hover:bg-slate-800 {{ request()->routeIs('dashboard*') ? 'bg-slate-800 text-white' : '' }} shrink-0" title="Dashboard">📊</a>
            @if(auth()->user()->isManager())
            <a href="{{ route('sync.index') }}" class="relative w-10 h-10 rounded-md flex items-center justify-center hover:bg-slate-800 {{ request()->routeIs('sync.*') ? 'bg-slate-800 text-white' : '' }} shrink-0" title="Sync">
                Sync
                @php($pendingSync = \App\Models\Invoice::whereIn('sync_status', ['pending', 'failed'])->count())
                @if($pendingSync > 0)
                <span class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 rounded-full bg-amber-500 text-black text-[10px] font-bold flex items-center justify-center">{{ $pendingSync }}</span>
                @endif
            </a>
            <a href="{{ route('settings.edit') }}" class="w-10 h-10 rounded-md flex items-center justify-center hover:bg-slate-800 {{ request()->routeIs('settings.*') ? 'bg-slate-800 text-white' : '' }} shrink-0" title="Settings">⚙</a>
            @endif
        </nav>
        <form method="POST" action="{{ route('logout') }}" class="shrink-0">
            @csrf
            <button type="submit" class="w-10 h-10 rounded-md flex items-center justify-center hover:bg-slate-800" title="Logout">⎋</button>
        </form>
    </aside>

    <header id="header" class="fixed inset-y-0 left-16 right-0 h-14 bg-white border-b border-slate-200 flex items-center justify-between px-3 md:px-6 no-print transition-all duration-200">
        <div class="flex items-center gap-2 md:gap-3 min-w-0">
            <button id="sidebar-toggle" type="button" class="w-8 h-8 rounded-md hover:bg-slate-100 flex items-center justify-center text-slate-600 shrink-0" title="Toggle sidebar">☰</button>
            <h1 class="text-base font-semibold text-slate-700 truncate">@yield('title', 'Register')</h1>
            @if(request()->attributes->get('posProfile'))
            <span class="hidden md:inline text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-500 truncate">{{ request()->attributes->get('posProfile')->name }}</span>
            @endif
        </div>
        <div class="flex items-center gap-2 md:gap-3 text-sm text-slate-500 shrink-0">
            <span class="font-medium text-slate-700 truncate hidden sm:inline">{{ auth()->user()->name }}</span>
            <span class="text-xs px-2 py-0.5 rounded-full bg-brand-50 text-brand-700">{{ ucfirst(auth()->user()->role) }}</span>
        </div>
    </header>

    <main id="main" class="ml-16 mt-14 p-3 md:p-6 min-h-[calc(100vh-3.5rem)] transition-all duration-200">
        @yield('content')
    </main>
</div>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const header = document.getElementById('header');
    const main = document.getElementById('main');
    const toggle = document.getElementById('sidebar-toggle');
    if (!sidebar || !toggle || !header || !main) return;

    function setSidebar(collapsed) {
        sidebar.classList.toggle('w-16', !collapsed);
        sidebar.classList.toggle('w-0', collapsed);
        header.classList.toggle('left-16', !collapsed);
        header.classList.toggle('left-0', collapsed);
        main.classList.toggle('ml-16', !collapsed);
        main.classList.toggle('ml-0', collapsed);
        try { localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0'); } catch (e) {}
    }

    let collapsed;
    try { collapsed = localStorage.getItem('sidebar-collapsed') === '1'; }
    catch (e) { collapsed = window.innerWidth < 1024; }
    if (collapsed === false && window.innerWidth < 1024) collapsed = true;
    setSidebar(collapsed);

    toggle.addEventListener('click', () => setSidebar(!sidebar.classList.contains('w-0')));
})();
</script>
@else
    {{-- Profile not selected: show the picker without the sidebar --}}
    <main class="min-h-screen">
        @yield('content')
    </main>
@endif
@else
    <main class="min-h-screen">
        @yield('content')
    </main>
@endauth

@yield('scripts')
</body>
</html>
