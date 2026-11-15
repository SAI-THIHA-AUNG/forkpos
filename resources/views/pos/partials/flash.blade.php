@php
    $alerts = [];
    foreach (['success','info','warning','error'] as $type) {
        if (session()->has($type)) $alerts[$type] = session($type);
    }
@endphp
@if (!empty($alerts))
<div class="mb-4 space-y-2">
    @foreach ($alerts as $type => $msg)
    <div class="flex items-start gap-2 p-3 rounded-lg border text-sm
        @if($type==='success') bg-green-50 border-green-200 text-green-800
        @elseif($type==='info') bg-blue-50 border-blue-200 text-blue-800
        @elseif($type==='warning') bg-amber-50 border-amber-200 text-amber-800
        @else bg-red-50 border-red-200 text-red-800 @endif">
        <span>{{ $msg }}</span>
    </div>
    @endforeach
</div>
@endif
