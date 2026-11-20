{{--
    The notification inbox as a page: where the bell leads
    without JavaScript (NotificationController::index). Newest first, at
    most App\Support\NotificationInbox::LIMIT.

    @var list<array<string, mixed>> $notifications  NotificationInbox::present() arrays
    @var int $unreadCount
--}}
<x-layouts.app title="Inbox">
    <header class="flex min-h-12 items-center gap-2 border-b border-edge px-4 py-2 shadow-sm">
        <span aria-hidden="true" class="text-xl">🔔</span>
        <h1 class="font-semibold text-fg-strong">Inbox</h1>
        <span class="text-sm text-fg-subtle" data-inbox-unread-count>{{ $unreadCount === 0 ? 'Nothing unread' : $unreadCount.' unread' }}</span>
        <div class="ml-auto flex items-center gap-2">
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="rounded px-2 py-1 text-sm text-fg-muted hover:bg-active hover:text-fg-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]">Mark all read</button>
                </form>
            @endif
            <a href="{{ route('dashboard') }}" class="rounded px-2 py-1 text-sm text-fg-muted hover:bg-active hover:text-fg-strong">Back to dashboard</a>
        </div>
    </header>

    <div class="flex-1 overflow-y-auto">
        @if ($notifications === [])
            <p class="px-4 py-8 text-center text-fg-subtle" data-inbox-empty>No mentions yet. When someone mentions you, it shows up here.</p>
        @else
            <ul class="mx-auto max-w-3xl" aria-label="Mentions">
                @foreach ($notifications as $item)
                    @include('notifications.item')
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.app>
