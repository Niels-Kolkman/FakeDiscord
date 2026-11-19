{{--
    A channel's pinned messages (ISSUE-20), newest pin first. With
    JavaScript the channel page shows the same list in a panel
    (channel-messages.js, renderPin()); this page is for links and for
    browsers without it. Moderators can unpin from here.

    @var \App\Models\Server $server
    @var \App\Models\Channel $channel
    @var \Illuminate\Support\Collection<int, \App\Models\Message> $pins
    @var bool $canManageMessages
--}}
<x-layouts.app :title="'Pinned messages · #'.$channel->name.' · '.$server->name">
    <x-slot:sidebar>
        @include('servers.partials.sidebar', ['activeChannel' => $channel])
    </x-slot:sidebar>

    <x-slot:aside>
        @include('servers.partials.member-list')
    </x-slot:aside>

    <header class="flex min-h-12 items-center gap-2 border-b border-edge px-4 py-2 shadow-sm">
        <span aria-hidden="true" class="text-xl text-fg-faint">📌</span>
        <h1 class="font-semibold text-fg-strong">Pinned messages in #{{ $channel->name }}</h1>
        <a href="{{ route('channels.show', [$server, $channel]) }}" class="ml-auto text-sm text-link hover:underline">Back to #{{ $channel->name }}</a>
    </header>

    <section class="flex-1 overflow-y-auto p-4">
        @if ($errors->has('message'))
            <p role="alert" class="mb-3 rounded bg-red-500/10 px-3 py-2 text-sm text-red-300">{{ $errors->first('message') }}</p>
        @endif

        @if ($pins->isEmpty())
            <p class="text-fg-muted" data-pins-empty>This channel has no pinned messages yet.</p>
        @else
            <ul class="space-y-2" aria-label="Pinned messages" data-pins-list>
                @foreach ($pins as $pin)
                    <li class="rounded-lg border border-edge bg-surface-2 p-3" data-pin data-message-id="{{ $pin->id }}">
                        <p class="flex items-baseline gap-2">
                            <span class="truncate font-medium text-fg-strong">{{ $pin->authorName() }}</span>
                            <time class="shrink-0 text-xs text-fg-subtle" datetime="{{ $pin->created_at?->toIso8601String() }}">{{ $pin->created_at?->format('Y-m-d H:i') }}</time>
                        </p>
                        @if ($pin->content !== '')
                            <p class="whitespace-pre-wrap break-words text-fg">{{ $pin->content }}</p>
                        @endif
                        @if ($pin->attachments->isNotEmpty())
                            <p class="text-sm text-fg-subtle">{{ trans_choice(':count attachment|:count attachments', $pin->attachments->count()) }}</p>
                        @endif
                        <div class="mt-2 flex items-center gap-3 text-xs text-fg-subtle">
                            <span>Pinned {{ $pin->pinned_at?->format('Y-m-d H:i') }}@if ($pin->pinnedBy) by {{ $pin->pinnedBy->name }}@endif</span>
                            <a href="{{ route('channels.show', [$server, $channel]) }}#message-{{ $pin->id }}" class="text-link hover:underline">Jump</a>
                            @if ($canManageMessages)
                                <form method="POST" action="{{ route('pins.destroy', [$server, $channel, $pin]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-400 hover:underline">Unpin</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
