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
        input[type=number].no-spin::-webkit-inner-spin-button,
        input[type=number].no-spin::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number].no-spin { -moz-appearance:textfield; }
        @media print {
            .no-print { display: none !important; }
            .print-area { position: absolute; left: 0; top: 0; width: 100%; }
        }
        /* Collapsed sidebar: icons only, hide labels, center icons */
        #sidebar .nav-text, #sidebar .brand-text { white-space: nowrap; }
        #sidebar.collapsed .nav-text, #sidebar.collapsed .brand-text { display: none; }
        #sidebar.collapsed .brand,
        #sidebar.collapsed nav a,
        #sidebar.collapsed form button { justify-content: center; padding-left: 0.25rem; padding-right: 0.25rem; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased">
@auth
@if(session()->has('pos_profile_id'))
<div>
    <aside id="sidebar" class="no-print fixed inset-y-0 left-0 w-56 bg-slate-900 text-slate-300 flex flex-col py-4 z-30 transition-all duration-200 overflow-hidden whitespace-nowrap">
        <a href="{{ route('home') }}" class="brand flex items-center gap-2 px-4 mb-6" title="Home - choose POS profile">
            <span class="w-8 h-8 rounded-lg bg-brand-600 text-white flex items-center justify-center font-bold text-lg shrink-0">F</span>
            <span class="brand-text font-semibold text-white text-sm">ForkPOS</span>
        </a>
        <nav class="flex-1 flex flex-col gap-1 px-2">
            <a href="{{ route('home') }}" class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('home') ? 'bg-slate-800 text-white' : '' }}" title="Home">
                <span class="w-5 text-center text-lg shrink-0">🏠</span><span class="nav-text text-sm">Home</span>
            </a>
            <a href="{{ route('pos.register') }}" class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('pos.register') ? 'bg-slate-800 text-white' : '' }}" title="Register">
                <span class="w-5 text-center text-lg shrink-0">🛒</span><span class="nav-text text-sm">Register</span>
            </a>
            <a href="{{ route('drafts.index') }}" class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('drafts.*') ? 'bg-slate-800 text-white' : '' }}" title="Drafts">
                <span class="w-5 text-center text-lg shrink-0">📝</span><span class="nav-text text-sm">Drafts</span>
            </a>
            <a href="{{ route('shifts.current') }}" class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('shifts.*') ? 'bg-slate-800 text-white' : '' }}" title="Shifts">
                <span class="w-5 text-center text-lg shrink-0">🕐</span><span class="nav-text text-sm">Shifts</span>
            </a>
            <a href="{{ route('invoices.index') }}" class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('invoices.*') && request('type') !== 'return' ? 'bg-slate-800 text-white' : '' }}" title="Sales">
                <span class="w-5 text-center text-lg shrink-0">🧾</span><span class="nav-text text-sm">Sales</span>
            </a>
            <a href="{{ route('invoices.index', ['type' => 'return']) }}" class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('invoices.*') && request('type') === 'return' ? 'bg-slate-800 text-white' : '' }}" title="Returns">
                <span class="w-5 text-center text-lg shrink-0">↩️</span><span class="nav-text text-sm">Returns</span>
            </a>
            <a href="{{ route('customers.index') }}" class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('customers.*') ? 'bg-slate-800 text-white' : '' }}" title="Customers">
                <span class="w-5 text-center text-lg shrink-0">👤</span><span class="nav-text text-sm">Customers</span>
            </a>
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('dashboard*') ? 'bg-slate-800 text-white' : '' }}" title="Dashboard">
                <span class="w-5 text-center text-lg shrink-0">📊</span><span class="nav-text text-sm">Dashboard</span>
            </a>
            @if(auth()->user()->isManager())
            <a href="{{ route('sync.index') }}" class="relative flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('sync.*') ? 'bg-slate-800 text-white' : '' }}" title="Sync">
                <span class="w-5 text-center text-lg shrink-0">🔄</span><span class="nav-text text-sm">Sync</span>
                @php($pendingSync = \App\Models\Invoice::whereIn('sync_status', ['pending', 'failed'])->count())
                @if($pendingSync > 0)
                <span class="nav-text ml-auto min-w-5 h-5 px-1 rounded-full bg-amber-500 text-black text-[11px] font-bold flex items-center justify-center">{{ $pendingSync }}</span>
                @endif
            </a>
            <a href="{{ route('users.index') }}" class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('users.*') ? 'bg-slate-800 text-white' : '' }}" title="Users">
                <span class="w-5 text-center text-lg shrink-0">👥</span><span class="nav-text text-sm">Users</span>
            </a>
            <a href="{{ route('settings.edit') }}" class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 {{ request()->routeIs('settings.*') ? 'bg-slate-800 text-white' : '' }}" title="Settings">
                <span class="w-5 text-center text-lg shrink-0">⚙️</span><span class="nav-text text-sm">Settings</span>
            </a>
            @endif
        </nav>
        <form method="POST" action="{{ route('logout') }}" class="px-2 mt-2">
            @csrf
            <button type="submit" class="w-full flex items-center gap-3 px-2 py-2 rounded-md hover:bg-slate-800 text-slate-300" title="Logout">
                <span class="w-5 text-center text-lg shrink-0">⎋</span><span class="nav-text text-sm">Logout</span>
            </button>
        </form>
    </aside>

    <header id="header" class="fixed inset-y-0 left-56 right-0 h-14 bg-white border-b border-slate-200 flex items-center justify-between px-3 md:px-6 no-print transition-all duration-200">
        <div class="flex items-center gap-2 md:gap-3 min-w-0">
            <button id="sidebar-toggle" type="button" class="w-8 h-8 rounded-md hover:bg-slate-100 flex items-center justify-center text-slate-600 shrink-0" title="Toggle sidebar">☰</button>
            <button type="button" onclick="posGoBack()" class="w-8 h-8 rounded-md hover:bg-slate-100 flex items-center justify-center text-slate-600 shrink-0" title="Back">&larr;</button>
            <a href="{{ route('home') }}" class="w-8 h-8 rounded-md hover:bg-slate-100 flex items-center justify-center text-slate-600 shrink-0" title="Home">🏠</a>
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

    <main id="main" class="ml-56 mt-14 p-3 md:p-6 min-h-[calc(100vh-3.5rem)] transition-all duration-200">
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
        sidebar.classList.toggle('collapsed', collapsed);
        sidebar.classList.toggle('w-56', !collapsed);
        sidebar.classList.toggle('w-16', collapsed);
        header.classList.toggle('left-56', !collapsed);
        header.classList.toggle('left-16', collapsed);
        main.classList.toggle('ml-56', !collapsed);
        main.classList.toggle('ml-16', collapsed);
        try { localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0'); } catch (e) {}
    }

    let collapsed;
    try { collapsed = localStorage.getItem('sidebar-collapsed') === '1'; }
    catch (e) { collapsed = window.innerWidth < 1024; }
    if (collapsed === false && window.innerWidth < 1024) collapsed = true;
    setSidebar(collapsed);

    toggle.addEventListener('click', () => setSidebar(!sidebar.classList.contains('collapsed')));
})();
</script>
@else
    {{-- No profile selected (profile picker, settings, users, sync): a slim top bar
         so these pages are never dead ends. Setup steps stay distraction-free. --}}
    @unless(request()->routeIs('setup.*'))
    <header id="topbar" class="no-print sticky top-0 z-30 h-14 bg-white border-b border-slate-200 flex items-center justify-between gap-2 px-3 md:px-6">
        <div class="flex items-center gap-2 md:gap-3 min-w-0">
            <a href="{{ route('home') }}" class="flex items-center gap-2 shrink-0" title="Home">
                <span class="w-8 h-8 rounded-lg bg-brand-600 text-white flex items-center justify-center font-bold text-lg">F</span>
                <span class="font-semibold text-slate-800 text-sm hidden sm:inline">ForkPOS</span>
            </a>
            <nav class="flex items-center gap-1 text-sm text-slate-600 min-w-0 overflow-x-auto">
                <a href="{{ route('home') }}" class="px-3 py-1.5 rounded-md hover:bg-slate-100 {{ request()->routeIs('home') ? 'bg-slate-100 text-slate-900' : '' }}">Home</a>
                @if(auth()->user()->isManager())
                <a href="{{ route('sync.index') }}" class="px-3 py-1.5 rounded-md hover:bg-slate-100 {{ request()->routeIs('sync.*') ? 'bg-slate-100 text-slate-900' : '' }}">Sync</a>
                <a href="{{ route('users.index') }}" class="px-3 py-1.5 rounded-md hover:bg-slate-100 {{ request()->routeIs('users.*') ? 'bg-slate-100 text-slate-900' : '' }}">Users</a>
                <a href="{{ route('settings.edit') }}" class="px-3 py-1.5 rounded-md hover:bg-slate-100 {{ request()->routeIs('settings.*') ? 'bg-slate-100 text-slate-900' : '' }}">Settings</a>
                @endif
            </nav>
        </div>
        <div class="flex items-center gap-2 md:gap-3 text-sm text-slate-500 shrink-0">
            <span class="font-medium text-slate-700 truncate hidden sm:inline">{{ auth()->user()->name }}</span>
            <span class="text-xs px-2 py-0.5 rounded-full bg-brand-50 text-brand-700">{{ ucfirst(auth()->user()->role) }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="px-3 py-1.5 rounded-md hover:bg-slate-100 text-slate-600" title="Logout">Logout</button>
            </form>
        </div>
    </header>
    @endunless

    <main class="min-h-[calc(100vh-3.5rem)]">
        @yield('content')
    </main>
@endif
@else
    <main class="min-h-screen">
        @yield('content')
    </main>
@endauth

@yield('scripts')
<script>
    // Global "Back" control: same-origin history only, otherwise fall back to Home.
    function posGoBack() {
        let sameOrigin = false;
        try {
            sameOrigin = !!document.referrer && new URL(document.referrer).origin === window.location.origin;
        } catch (e) {
            sameOrigin = false;
        }
        if (sameOrigin && window.history.length > 1) {
            window.history.back();
            return;
        }
        window.location.href = @json(route('home'));
    }
</script>
</body>
</html>
