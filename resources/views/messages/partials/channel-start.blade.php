{{-- "Start of the channel" header of the message panel (messages/panel.blade.php). @var \App\Models\Channel $channel --}}
<div aria-hidden="true" class="mb-2 flex h-16 w-16 items-center justify-center rounded-full bg-[#41434a] text-4xl text-white">{{ $channel->isVoice() ? '🔊' : '#' }}</div>
<h2 class="text-3xl font-bold text-white">Welcome to #{{ $channel->name }}!</h2>
<p class="text-[#b5bac1]">This is the start of the #{{ $channel->name }} channel.</p>
@if ($channel->topic)
    <p class="mt-1 whitespace-pre-line break-words text-[#b5bac1]">{{ $channel->topic }}</p>
@endif
