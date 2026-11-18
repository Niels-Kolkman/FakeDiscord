{{--
    Direct messages in the left sidebar (ISSUE-23): the signed-in user's 1:1
    DMs, latest activity first (App\Models\Conversation::sidebarFor()).
    A DM nobody has written in yet only shows while it is open.

    resources/js/direct-messages.js reloads [data-dm-list] when
    App\Events\ConversationActivity arrives, so a new message moves its
    conversation to the top, also for DMs that were not listed yet.

    @var \App\Models\Conversation|null $activeConversation
--}}
@php
    $viewer = auth()->user();
    $directMessages = $viewer ? \App\Models\Conversation::sidebarFor($viewer, $activeConversation ?? null) : collect();
@endphp
<section aria-labelledby="dm-list-heading" class="mb-4" data-dm-list>
    <h2 id="dm-list-heading" class="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-[#949ba4]">Direct messages</h2>
    @if ($directMessages->isEmpty())
        <p class="px-2 text-sm text-[#949ba4]">No direct messages yet. Message a friend from the Friends page.</p>
    @else
        <ul class="space-y-0.5">
            @foreach ($directMessages as $dm)
                @php
                    $person = $dm->otherParticipant($viewer);
                    $isActive = ($activeConversation ?? null)?->is($dm) ?? false;
                @endphp
                <li data-dm-id="{{ $dm->id }}">
                    <a
                        href="{{ route('dms.show', $dm) }}"
                        @if ($isActive) aria-current="page" @endif
                        class="flex items-center gap-2 rounded px-2 py-1.5 text-[#b5bac1] hover:bg-[#35373c] hover:text-white aria-[current=page]:bg-[#404249] aria-[current=page]:text-white"
                    >
                        @if ($dm->isGroup())
                            <span aria-hidden="true" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#5865f2] text-sm text-white">👥</span>
                        @elseif ($person)
                            <x-user-avatar :user="$person" :size="32" />
                        @else
                            <span aria-hidden="true" class="h-8 w-8 shrink-0 rounded-full bg-[#4e5058]"></span>
                        @endif
                        <span class="min-w-0">
                            <span class="block truncate">{{ $dm->displayName($viewer) }}</span>
                            @if ($dm->isGroup())
                                <span class="block truncate text-xs text-[#949ba4]">{{ trans_choice('{1} 1 member|[2,*] :count members', $dm->users->count(), ['count' => $dm->users->count()]) }}</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
