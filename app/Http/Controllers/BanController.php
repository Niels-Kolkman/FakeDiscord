<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The ban list of a server and lifting bans (ISSUE-29): Ban Members
 * (ServerPolicy::manageBans). Banning is MemberModerationController::ban.
 * A lifted ban lets the user join again with an invite; they are not
 * added back.
 */
class BanController extends Controller
{
    public function index(Server $server): View
    {
        Gate::authorize('manageBans', $server);

        return view('moderation.bans', [
            'server' => $server,
            'bans' => $server->bans()->with(['user', 'bannedBy'])->latest('id')->get(),
        ]);
    }

    /**
     * {user} is the banned user; a user who is not banned is a 404.
     */
    public function destroy(Server $server, User $user): RedirectResponse
    {
        Gate::authorize('manageBans', $server);

        $server->bans()->where('user_id', $user->getKey())->firstOrFail()->delete();

        return redirect()
            ->route('bans.index', $server)
            ->with('status', "{$user->name} has been unbanned.");
    }
}
