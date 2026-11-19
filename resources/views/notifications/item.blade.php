{{--
    One entry of the notification inbox (ISSUE-33): the page
    (notifications/index.blade.php). renderNotification() in
    resources/js/notifications.js builds the same markup for the panel;
    keep the two in sync.

    The link opens NotificationController::open, which marks it read and
    goes to the message.

    @var array<string, mixed> $item  App\Support\NotificationInbox::present()
--}}
<li
    class="border-b border-[#1f2023] last:border-b-0 data-[unread]:bg-[#5865f2]/10"
    data-notification
    data-notification-id="{{ $item['id'] }}"
    @unless ($item['read']) data-unread @endunless
>
    <a href="{{ $item['open_url'] }}" class="flex items-start gap-3 px-4 py-3 hover:bg-[#35373c] focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#5865f2]" data-notification-link>
        @if ($item['avatar_url'])
            <img src="{{ $item['avatar_url'] }}" alt="" width="32" height="32" class="h-8 w-8 shrink-0 rounded-full object-cover">
        @else
            <span aria-hidden="true" class="h-8 w-8 shrink-0 rounded-full bg-[#4e5058]"></span>
        @endif
        <span class="min-w-0 flex-1">
            <span class="block text-sm text-[#dbdee1]"><span class="font-semibold text-white" data-notification-author>{{ $item['author_name'] }}</span> <span data-notification-label>{{ $item['label'] }}</span></span>
            <span class="block truncate text-xs text-[#949ba4]" data-notification-where>{{ $item['server_name'] }} · #{{ $item['channel_name'] }}@if ($item['created_at']) · <time datetime="{{ $item['created_at'] }}" data-local-time>{{ \Illuminate\Support\Carbon::parse($item['created_at'])->format('Y-m-d H:i') }}</time>@endif</span>
            <span class="mt-0.5 block truncate text-sm text-[#b5bac1]" data-notification-excerpt>{{ $item['excerpt'] }}</span>
        </span>
        @unless ($item['read'])
            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-[#5865f2]" data-notification-dot><span class="sr-only">(unread)</span></span>
        @endunless
    </a>
</li>
