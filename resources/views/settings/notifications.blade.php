{{--
    Notification settings (ISSUE-34, NotificationSettingsController::edit).

    Browser notifications are per browser: resources/js/push-notifications.js
    fills [data-push-settings] (asks for permission, or turns them off here
    with a localStorage flag). Mutes are per user (notification_mutes): a
    muted server or channel sends no browser notifications, but mentions
    still land in the inbox and unread badges still show.

    @var \Illuminate\Support\Collection<int, \App\Models\Server> $servers
    @var list<int> $mutedServerIds
    @var \Illuminate\Support\Collection<int, \App\Models\Channel> $mutedChannels
--}}
<x-layouts.auth title="Notifications">
    <section aria-labelledby="push-heading" class="mb-8" data-push-settings>
        <h2 id="push-heading" class="mb-1 text-lg font-semibold text-white">Desktop notifications</h2>
        <p class="mb-3 text-sm text-[#949ba4]">
            Get a notification from your browser when someone mentions you or sends you a DM while this tab is in the background.
            This setting is for this browser only.
        </p>
        <p role="status" class="mb-3 text-sm text-[#dbdee1]" data-push-status>Desktop notifications need JavaScript.</p>
        <button type="button" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]" data-push-toggle hidden>Enable desktop notifications</button>
    </section>

    <section aria-labelledby="servers-heading" class="mb-8">
        <h2 id="servers-heading" class="mb-1 text-lg font-semibold text-white">Servers</h2>
        <p class="mb-3 text-sm text-[#949ba4]">A muted server sends no desktop notifications. Mentions still show in your inbox.</p>

        @if ($servers->isEmpty())
            <p class="text-sm text-[#949ba4]">You are not in any servers yet.</p>
        @else
            <ul class="space-y-1">
                @foreach ($servers as $server)
                    @php($serverMuted = in_array((int) $server->id, $mutedServerIds, true))
                    <li class="flex items-center gap-2 rounded px-2 py-1 hover:bg-[#35373c]" data-mute-server="{{ $server->id }}" @if ($serverMuted) data-muted @endif>
                        <x-server-icon :server="$server" :size="24" />
                        <span class="min-w-0 flex-1 truncate">{{ $server->name }}</span>
                        @if ($serverMuted)
                            <span class="text-xs text-[#949ba4]">Muted</span>
                        @endif
                        <form method="POST" action="{{ $serverMuted ? route('servers.unmute', $server) : route('servers.mute', $server) }}">
                            @csrf
                            @method($serverMuted ? 'DELETE' : 'PUT')
                            <button type="submit" class="rounded bg-[#4e5058] px-2 py-0.5 text-xs font-medium text-white hover:bg-[#6d6f78]">
                                {{ $serverMuted ? 'Unmute' : 'Mute' }}<span class="sr-only"> {{ $server->name }}</span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="channels-heading" class="mb-8">
        <h2 id="channels-heading" class="mb-1 text-lg font-semibold text-white">Muted channels</h2>
        <p class="mb-3 text-sm text-[#949ba4]">Mute a channel with 🔕 next to its name in the channel list.</p>

        @if ($mutedChannels->isEmpty())
            <p class="text-sm text-[#949ba4]" data-no-muted-channels>No muted channels.</p>
        @else
            <ul class="space-y-1">
                @foreach ($mutedChannels as $channel)
                    <li class="flex items-center gap-2 rounded px-2 py-1 hover:bg-[#35373c]" data-mute-channel="{{ $channel->id }}">
                        <span class="min-w-0 flex-1 truncate">#{{ $channel->name }} <span class="text-xs text-[#949ba4]">in {{ $channel->server->name }}</span></span>
                        <form method="POST" action="{{ route('channels.unmute', [$channel->server_id, $channel]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded bg-[#4e5058] px-2 py-0.5 text-xs font-medium text-white hover:bg-[#6d6f78]">
                                Unmute<span class="sr-only"> #{{ $channel->name }}</span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="mt-6 text-center">
        <a href="{{ route('dashboard') }}" class="text-sm font-medium text-[#00a8fc] hover:underline">Back</a>
    </p>
</x-layouts.auth>
