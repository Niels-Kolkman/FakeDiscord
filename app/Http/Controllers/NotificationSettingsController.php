<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Server;
use App\Models\User;
use App\Support\NotificationMutes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Notification settings: the settings page (browser
 * notifications for this browser, muted servers and channels) and the
 * mute / unmute routes used by it and by the channel sidebar.
 *
 * Muting needs ServerPolicy::view (members only) and, for a channel,
 * ChannelPolicy::view; anything else is the policies' 403 / 404. The
 * routes answer JSON ({muted}) or go back with a status line.
 */
class NotificationSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $this->user($request);
        $mutes = NotificationMutes::forUser($user);

        $servers = $user->servers()->orderBy('servers.name')->get();
        $mutedChannels = Channel::query()
            ->whereKey($mutes['channels'])
            ->whereIn('server_id', $servers->modelKeys())
            ->with('server')
            ->orderBy('server_id')
            ->orderBy('name')
            ->get()
            ->filter(fn (Channel $channel): bool => $user->can('view', $channel))
            ->values();

        return view('settings.notifications', [
            'servers' => $servers,
            'mutedServerIds' => $mutes['servers'],
            'mutedChannels' => $mutedChannels,
        ]);
    }

    public function muteServer(Request $request, Server $server): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $server);
        NotificationMutes::muteServer($this->user($request), $server);

        return $this->respond($request, true, "Muted {$server->name}.");
    }

    public function unmuteServer(Request $request, Server $server): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $server);
        NotificationMutes::unmuteServer($this->user($request), $server);

        return $this->respond($request, false, "Unmuted {$server->name}.");
    }

    public function muteChannel(Request $request, Server $server, Channel $channel): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $server);
        Gate::authorize('view', $channel);
        NotificationMutes::muteChannel($this->user($request), $channel);

        return $this->respond($request, true, "Muted #{$channel->name}.");
    }

    public function unmuteChannel(Request $request, Server $server, Channel $channel): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $server);
        Gate::authorize('view', $channel);
        NotificationMutes::unmuteChannel($this->user($request), $channel);

        return $this->respond($request, false, "Unmuted #{$channel->name}.");
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function respond(Request $request, bool $muted, string $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['muted' => $muted]);
        }

        return back()->with('status', $status);
    }
}
