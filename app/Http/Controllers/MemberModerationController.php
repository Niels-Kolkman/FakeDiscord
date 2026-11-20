<?php

namespace App\Http\Controllers;

use App\Actions\Moderation\BanMember;
use App\Actions\Moderation\KickMember;
use App\Actions\Moderation\TimeoutMember;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kicking, banning and timing out members. The page needs
 * ServerPolicy::moderateMembers; each action needs ServerPolicy::kick,
 * ::ban or ::timeout for that member, which also applies the role
 * hierarchy. Someone who is not a member is a 404.
 */
class MemberModerationController extends Controller
{
    public function index(Request $request, Server $server, PermissionService $permissions): View
    {
        Gate::authorize('moderateMembers', $server);

        /** @var User $viewer */
        $viewer = $request->user();

        $members = $server->memberships()
            ->with('user')
            ->get()
            ->sortBy(fn (ServerMember $member): string => mb_strtolower($member->displayName()))
            ->values()
            ->map(fn (ServerMember $member): array => [
                'member' => $member,
                'timedOutUntil' => $permissions->timedOutUntil($member->user, $server),
                'canKick' => $viewer->can('kick', [$server, $member->user]),
                'canBan' => $viewer->can('ban', [$server, $member->user]),
                'canTimeout' => $viewer->can('timeout', [$server, $member->user]),
            ]);

        return view('moderation.members', [
            'server' => $server,
            'members' => $members,
            'durations' => TimeoutMember::DURATIONS,
            'deleteMessageOptions' => BanMember::DELETE_MESSAGE_OPTIONS,
            'maxReasonLength' => BanMember::MAX_REASON_LENGTH,
        ]);
    }

    public function kick(Server $server, User $user, KickMember $kick): RedirectResponse
    {
        Gate::authorize('kick', [$server, $user]);

        $kick->kick($server, $user);

        return $this->back($server, "{$user->name} has been kicked from the server.");
    }

    /**
     * Fields: `reason` (optional) and `delete_messages`, seconds of message
     * history to delete (one of BanMember::DELETE_MESSAGE_OPTIONS, default 0).
     */
    public function ban(Request $request, Server $server, User $user, BanMember $ban): RedirectResponse
    {
        Gate::authorize('ban', [$server, $user]);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:'.BanMember::MAX_REASON_LENGTH],
            'delete_messages' => ['nullable', 'integer', Rule::in(array_keys(BanMember::DELETE_MESSAGE_OPTIONS))],
        ]);

        /** @var User $moderator */
        $moderator = $request->user();

        $deleted = $ban->ban(
            $server,
            $user,
            $moderator,
            $validated['reason'] ?? null,
            (int) ($validated['delete_messages'] ?? 0),
        );

        $status = "{$user->name} has been banned from the server.";
        if ($deleted > 0) {
            $status .= $deleted === 1 ? ' 1 message was deleted.' : " {$deleted} messages were deleted.";
        }

        return $this->back($server, $status);
    }

    /**
     * Field `duration`: seconds, one of TimeoutMember::DURATIONS.
     */
    public function timeout(Request $request, Server $server, User $user, TimeoutMember $timeouts): RedirectResponse
    {
        Gate::authorize('timeout', [$server, $user]);

        $validated = $request->validate([
            'duration' => ['required', 'integer', Rule::in(array_keys(TimeoutMember::DURATIONS))],
        ]);

        $seconds = (int) $validated['duration'];
        $timeouts->timeout($server, $user, $seconds);

        return $this->back($server, "{$user->name} has been timed out for ".TimeoutMember::DURATIONS[$seconds].'.');
    }

    public function removeTimeout(Server $server, User $user, TimeoutMember $timeouts): RedirectResponse
    {
        Gate::authorize('timeout', [$server, $user]);

        return $this->back($server, $timeouts->remove($server, $user)
            ? "{$user->name}'s timeout has been removed."
            : "{$user->name} is not timed out.");
    }

    private function back(Server $server, string $status): RedirectResponse
    {
        return redirect()->route('members.index', $server)->with('status', $status);
    }
}
