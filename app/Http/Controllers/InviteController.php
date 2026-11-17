<?php

namespace App\Http\Controllers;

use App\Actions\Servers\AcceptInvite;
use App\Actions\Servers\CreateInvite;
use App\Exceptions\InviteUnavailableException;
use App\Http\Requests\StoreInviteRequest;
use App\Models\Invite;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Members create and revoke invites under /servers/{server}/invites;
 * anyone signed in opens and accepts them at /invite/{code}.
 */
class InviteController extends Controller
{
    public function index(Request $request, Server $server): View
    {
        Gate::authorize('createInvite', $server);

        /** @var User $user */
        $user = $request->user();

        // The owner sees every invite; other members only their own.
        $invites = $server->invites()
            ->usable()
            ->unless($user->can('manageInvites', $server), fn ($q) => $q->where('inviter_id', $user->getKey()))
            ->with('inviter')
            ->latest('id')
            ->get();

        return view('invites.index', [
            'server' => $server,
            'invites' => $invites,
            'expiryOptions' => StoreInviteRequest::EXPIRY_OPTIONS,
            'maxUsesOptions' => StoreInviteRequest::MAX_USES_OPTIONS,
            'defaultExpiry' => StoreInviteRequest::DEFAULT_EXPIRY,
        ]);
    }

    public function store(StoreInviteRequest $request, Server $server, CreateInvite $invites): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $invite = $invites->create($server, $user, $request->expiresInMinutes(), $request->maxUses());

        return redirect()
            ->route('servers.invites.index', $server)
            ->with('invite_url', $invite->url());
    }

    public function destroy(Server $server, Invite $invite): RedirectResponse
    {
        Gate::authorize('delete', $invite);

        $invite->delete();

        return redirect()
            ->route('servers.invites.index', $server)
            ->with('status', 'The invite has been revoked.');
    }

    /**
     * Preview: which server the invite is for, and a join button.
     */
    public function show(Request $request, string $code, AcceptInvite $accept): View|Response
    {
        $invite = $this->findByCode($code);

        /** @var User $user */
        $user = $request->user();

        try {
            $accept->check($invite, $user);
        } catch (InviteUnavailableException $e) {
            return $this->unavailable($invite, $e);
        }

        $server = $invite->server;

        return view('invites.show', [
            'invite' => $invite,
            'server' => $server,
            'memberCount' => $server->memberships()->count(),
            'isMember' => $server->hasMember($user),
        ]);
    }

    public function accept(Request $request, string $code, AcceptInvite $accept): RedirectResponse|Response
    {
        $invite = $this->findByCode($code);

        /** @var User $user */
        $user = $request->user();

        try {
            $joined = $accept->accept($invite, $user);
        } catch (InviteUnavailableException $e) {
            return $this->unavailable($invite, $e);
        }

        $server = $invite->server;

        return redirect()->route('servers.show', $server)->with(
            'status',
            $joined ? "Welcome to {$server->name}!" : "You are already a member of {$server->name}.",
        );
    }

    private function findByCode(string $code): Invite
    {
        $invite = Invite::query()->where('code', $code)->with('server')->first();

        // Codes are case-sensitive, also on databases with a case-insensitive collation.
        abort_if($invite === null || $invite->code !== $code, 404);

        return $invite;
    }

    private function unavailable(Invite $invite, InviteUnavailableException $e): Response
    {
        return response()->view('invites.unavailable', [
            'server' => $invite->server,
            'reason' => $e->reason,
            'message' => $e->getMessage(),
        ], $e->status());
    }
}
