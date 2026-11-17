<?php

namespace App\Http\Controllers;

use App\Actions\Servers\LeaveServer;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Leaving a server. The owner is refused by ServerPolicy::leave until they
 * have transferred ownership (ServerOwnershipController).
 */
class ServerMembershipController extends Controller
{
    /**
     * Confirmation page. The owner sees why they cannot leave instead of a 403.
     */
    public function create(Server $server): View
    {
        Gate::authorize('view', $server);

        return view('servers.leave', [
            'server' => $server,
            'decision' => Gate::inspect('leave', $server),
        ]);
    }

    public function destroy(Request $request, Server $server, LeaveServer $members): RedirectResponse
    {
        Gate::authorize('leave', $server);

        /** @var User $user */
        $user = $request->user();

        $members->leave($server, $user);

        return redirect()->route('dashboard')->with('status', "You have left \"{$server->name}\".");
    }
}
