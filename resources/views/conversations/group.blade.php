{{--
    A group DM (App\Http\Controllers\DirectMessageController::show).

    The same message panel as a 1:1 DM, plus the group's settings: its
    people (the owner first, with Remove for the owner), Add friends (any
    participant, up to Conversation::MAX_PARTICIPANTS people), Rename (any
    participant; empty goes back to the people's names) and Leave. All of
    these are plain forms to App\Http\Controllers\GroupConversationController.

    resources/js/direct-messages.js reloads [data-group-details] when
    App\Events\ConversationUpdated arrives for this group, and goes to the
    friends page when the viewer is no longer in it.

    @var \App\Models\Conversation $conversation
    @var \Illuminate\Support\Collection<int, \App\Models\User> $members
    @var \Illuminate\Support\Collection<int, \App\Models\User> $invitable
    @var bool $isOwner
    @var \App\Support\MessageFeed $feed
    @var bool $canSendMessages
    @var int $incomingCount
--}}
@php
    $viewer = auth()->user();
    $viewerId = (int) auth()->id();
    $title = $conversation->displayName($viewer);
    $canManageMessages = false;
    $seatsLeft = max(0, \App\Models\Conversation::MAX_PARTICIPANTS - $members->count());
    $button = 'rounded px-3 py-1 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]';
@endphp
<x-layouts.app :title="$title">
    <x-slot:sidebar>
        @include('friends.partials.sidebar', ['activeConversation' => $conversation])
    </x-slot:sidebar>

    <div data-group-details data-conversation-id="{{ $conversation->id }}">
        <header class="flex min-h-12 items-center gap-2 border-b border-edge px-4 py-2 shadow-sm">
            <span aria-hidden="true" class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#5865f2] text-xs text-white">👥</span>
            <h1 class="truncate font-semibold text-fg-strong" data-group-name>{{ $title }}</h1>
            <span class="shrink-0 text-sm text-fg-subtle" data-group-count>{{ trans_choice('{1} 1 member|[2,*] :count members', $members->count(), ['count' => $members->count()]) }}</span>
            {{-- Mention notifications. --}}
            <div class="ml-auto"><x-inbox /></div>
        </header>

        <details class="border-b border-edge px-4 py-2 text-sm" @if ($errors->has('user_ids') || $errors->has('name')) open @endif>
            <summary class="cursor-pointer text-fg-muted hover:text-fg-strong">Group settings</summary>

            <div class="mt-3 grid gap-6 pb-2 md:grid-cols-2">
                <section aria-labelledby="group-members-heading">
                    <h2 id="group-members-heading" class="mb-2 text-xs font-semibold uppercase tracking-wide text-fg-subtle">
                        Members — {{ $members->count() }} / {{ \App\Models\Conversation::MAX_PARTICIPANTS }}
                    </h2>
                    <ul class="space-y-1" data-group-members>
                        @foreach ($members as $member)
                            <li class="flex items-center gap-2" data-member-id="{{ $member->id }}">
                                <x-user-avatar :user="$member" :size="24" />
                                <span class="min-w-0 flex-1 truncate text-fg">
                                    {{ $member->name }}
                                    @if ($conversation->isOwnedBy($member))
                                        <span class="ml-1 text-xs text-warn-fg" data-group-owner>Owner</span>
                                    @endif
                                    @if ((int) $member->id === $viewerId)
                                        <span class="ml-1 text-xs text-fg-subtle">(you)</span>
                                    @endif
                                </span>
                                @if ($isOwner && (int) $member->id !== $viewerId)
                                    <form method="POST" action="{{ route('dms.participants.destroy', [$conversation, $member]) }}" data-confirm="Remove {{ $member->name }} from the group?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="{{ $button }} text-danger-fg hover:bg-[#da373c] hover:text-white" aria-label="Remove {{ $member->name }} from the group">Remove</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <form method="POST" action="{{ route('dms.participants.destroy', [$conversation, $viewerId]) }}" class="mt-3" data-confirm="Leave {{ $title }}? You will need to be added again to come back.">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="{{ $button }} bg-[#da373c] text-white hover:bg-[#a12d2f]" data-leave-group>Leave group</button>
                    </form>
                </section>

                <div class="space-y-6">
                    <section aria-labelledby="group-rename-heading">
                        <h2 id="group-rename-heading" class="mb-2 text-xs font-semibold uppercase tracking-wide text-fg-subtle">Group name</h2>
                        <form method="POST" action="{{ route('dms.update', $conversation) }}" class="flex gap-2" data-rename-group>
                            @csrf
                            @method('PATCH')
                            <label for="group-name" class="sr-only">Group name</label>
                            <input
                                id="group-name"
                                type="text"
                                name="name"
                                value="{{ old('name', $conversation->name) }}"
                                maxlength="{{ \App\Models\Conversation::NAME_MAX_LENGTH }}"
                                placeholder="{{ $title }}"
                                @error('name') aria-invalid="true" aria-describedby="group-name-error" @enderror
                                class="min-w-0 flex-1 rounded bg-surface-3 px-2 py-1 text-fg-strong placeholder-fg-subtle outline-none focus:ring-2 focus:ring-[#5865f2]"
                            >
                            <button type="submit" class="{{ $button }} bg-[#5865f2] text-white hover:bg-[#4752c4]">Rename</button>
                        </form>
                        @error('name')
                            <p id="group-name-error" role="alert" class="mt-2 text-danger-fg">{{ $message }}</p>
                        @enderror
                    </section>

                    <section aria-labelledby="group-add-heading">
                        <h2 id="group-add-heading" class="mb-2 text-xs font-semibold uppercase tracking-wide text-fg-subtle">Add friends</h2>
                        @if ($seatsLeft === 0)
                            <p class="text-fg-subtle">This group is full.</p>
                        @elseif ($invitable->isEmpty())
                            <p class="text-fg-subtle">All your friends are already here.</p>
                        @else
                            <form method="POST" action="{{ route('dms.participants.store', $conversation) }}" data-add-to-group>
                                @csrf
                                <p class="mb-2 text-fg-muted">{{ trans_choice('{1} 1 spot left.|[2,*] :count spots left.', $seatsLeft, ['count' => $seatsLeft]) }}</p>
                                @include('conversations.partials.friend-picker', ['friends' => $invitable, 'idPrefix' => 'add-friend'])
                                <button type="submit" class="{{ $button }} mt-2 bg-[#5865f2] text-white hover:bg-[#4752c4]">Add to group</button>
                            </form>
                        @endif
                        @error('user_ids')
                            <p role="alert" class="mt-2 text-danger-fg">{{ $message }}</p>
                        @enderror
                    </section>
                </div>
            </div>
        </details>
    </div>

    @include('messages.panel', ['panel' => [
        'target' => 'conversation',
        'id' => $conversation->id,
        'label' => $title,
        'historyUrl' => route('dms.messages.index', $conversation),
        'storeUrl' => route('dms.messages.store', $conversation),
        'pinsUrl' => null,
        'broadcastChannel' => $conversation->broadcastChannel(),
        'typingChannel' => \App\Models\Conversation::typingChannelName((int) $conversation->id),
        'start' => 'conversations.partials.group-start',
        'readOnlyNotice' => __('You cannot send messages in this conversation.'),
    ]])
</x-layouts.app>
