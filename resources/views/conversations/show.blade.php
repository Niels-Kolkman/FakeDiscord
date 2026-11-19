{{--
    A 1:1 direct message (ISSUE-23, App\Http\Controllers\DirectMessageController).

    The same message panel as a channel (messages/panel.blade.php, driven
    by resources/js/channel-messages.js), with the DM's routes and its
    private "conversation.{id}" channel. There are no moderators in a DM,
    so only authors edit and delete their own messages, and there are no
    pins. When you cannot send (a block, or the other account is gone) the
    history stays readable and a notice replaces the composer.

    @var \App\Models\Conversation $conversation
    @var \App\Models\User|null $other
    @var \App\Support\MessageFeed $feed
    @var bool $canSendMessages
    @var int $incomingCount
--}}
@php
    $title = $other?->name ?? __('Deleted User');
    $viewerId = (int) auth()->id();
    $canManageMessages = false;
@endphp
<x-layouts.app :title="$title">
    <x-slot:sidebar>
        @include('friends.partials.sidebar', ['activeConversation' => $conversation])
    </x-slot:sidebar>

    <header class="flex min-h-12 items-center gap-2 border-b border-[#1f2023] px-4 py-2 shadow-sm">
        <span aria-hidden="true" class="text-xl text-[#80848e]">@</span>
        @if ($other)
            <x-user-avatar :user="$other" :size="24" />
        @endif
        <h1 class="truncate font-semibold text-white">{{ $title }}</h1>
        @if ($other)
            <span class="truncate text-sm text-[#949ba4]">{{ '@'.$other->username }}</span>
        @endif
        {{-- Mention notifications (ISSUE-33). --}}
        <div class="ml-auto"><x-inbox /></div>
    </header>

    @include('messages.panel', ['panel' => [
        'target' => 'conversation',
        'id' => $conversation->id,
        'label' => '@'.($other?->username ?? __('Deleted User')),
        'historyUrl' => route('dms.messages.index', $conversation),
        'storeUrl' => route('dms.messages.store', $conversation),
        'pinsUrl' => null,
        'broadcastChannel' => $conversation->broadcastChannel(),
        'typingChannel' => \App\Models\Conversation::typingChannelName((int) $conversation->id),
        'start' => 'conversations.partials.start',
        'readOnlyNotice' => __('You cannot send messages in this conversation.'),
    ]])
</x-layouts.app>
