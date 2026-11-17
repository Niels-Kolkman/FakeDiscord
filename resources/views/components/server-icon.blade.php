{{-- A server's icon, or its initials on a blurple circle when it has no uploaded icon. --}}
@props(['server', 'size' => 48])

@php($url = $server->iconUrl())

@if ($url)
    <img src="{{ $url }}" alt="{{ $server->name }}" width="{{ (int) $size }}" height="{{ (int) $size }}" {{ $attributes->merge(['class' => 'inline-block shrink-0 rounded-full object-cover']) }} style="width: {{ (int) $size }}px; height: {{ (int) $size }}px">
@else
    <span role="img" aria-label="{{ $server->name }}" {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#5865f2] font-semibold text-white']) }} style="width: {{ (int) $size }}px; height: {{ (int) $size }}px; font-size: {{ max(10, intdiv((int) $size, 3)) }}px">{{ $server->initials() }}</span>
@endif
