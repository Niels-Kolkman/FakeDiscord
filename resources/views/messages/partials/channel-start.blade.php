{{-- "Start of the channel" header of the message panel (messages/panel.blade.php). @var \App\Models\Channel $channel --}}
<div aria-hidden="true" class="mb-2 flex h-16 w-16 items-center justify-center rounded-full bg-active text-4xl text-fg-strong">{{ $channel->isVoice() ? '🔊' : '#' }}</div>
<h2 class="text-3xl font-bold text-fg-strong">Welcome to #{{ $channel->name }}!</h2>
<p class="text-fg-muted">This is the start of the #{{ $channel->name }} channel.</p>
@if ($channel->topic)
    <p class="mt-1 whitespace-pre-line break-words text-fg-muted">{{ $channel->topic }}</p>
@endif
