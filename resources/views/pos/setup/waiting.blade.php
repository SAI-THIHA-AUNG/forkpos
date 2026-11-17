@extends('layouts.app')
@section('title', 'Setup in progress')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-slate-100 p-6">
    <div class="max-w-md text-center bg-white rounded-2xl border border-slate-200 p-8">
        <h1 class="text-xl font-bold text-slate-800 mb-2">This terminal isn't set up yet</h1>
        <p class="text-sm text-slate-500 mb-6">A manager needs to connect ERPNext and choose the POS profiles before you can start a shift.</p>
        <form method="POST" action="{{ route('logout') }}">@csrf <button class="text-sm text-slate-500 hover:text-slate-700">Sign out</button></form>
    </div>
</div>
@endsection
