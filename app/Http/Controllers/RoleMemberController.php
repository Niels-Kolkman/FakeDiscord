<?php

namespace App\Http\Controllers;

use App\Actions\Roles\AssignRole;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Give a role to members and take it away again. Needs
 * ServerPolicy::manageRoles, like the rest of the role routes, and the role
 * must be below the actor's own highest role (RolePolicy::manage).
 */
class RoleMemberController extends Controller
{
    /**
     * Field `user_id`: a member of the server.
     */
    public function store(Request $request, Server $server, Role $role, AssignRole $roles): RedirectResponse
    {
        Gate::authorize('manage', $role);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::query()->findOrFail($validated['user_id']);
        $added = $roles->assign($role, $user);

        return redirect()
            ->route('roles.edit', [$server, $role])
            ->with('status', $added
                ? "{$user->name} now has the role \"{$role->name}\"."
                : "{$user->name} already has the role \"{$role->name}\".");
    }

    /**
     * {user} is scoped to the role's holders, so anyone else is a 404.
     */
    public function destroy(Server $server, Role $role, User $user, AssignRole $roles): RedirectResponse
    {
        Gate::authorize('manage', $role);

        $roles->remove($role, $user);

        return redirect()
            ->route('roles.edit', [$server, $role])
            ->with('status', "{$user->name} no longer has the role \"{$role->name}\".");
    }
}
