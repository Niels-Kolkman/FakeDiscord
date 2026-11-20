<?php

namespace App\Http\Controllers;

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\MoveRole;
use App\Enums\Permission;
use App\Http\Requests\StoreRoleRequest;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Server roles: list, create, edit, reorder and delete. Every
 * route needs ServerPolicy::manageRoles (the `can` middleware in
 * routes/web.php); scoped bindings make another server's role a 404.
 *
 * The role hierarchy: an existing role can only be opened,
 * changed, moved or deleted by someone who outranks it (RolePolicy::manage),
 * and a move cannot take it past their own highest role. A member who is not
 * the owner creates roles just below their own highest role, and only turns
 * on or off flags they have themselves (StoreRoleRequest).
 */
class RoleController extends Controller
{
    public function index(Server $server): View
    {
        // Makes sure @everyone exists, so it is always listed.
        $server->defaultRole();

        return view('roles.index', [
            'server' => $server,
            'roles' => $server->rolesInOrder()->withCount('users')->get(),
        ]);
    }

    public function create(Server $server): View
    {
        return view('roles.create', ['server' => $server, 'permissions' => Permission::cases()]);
    }

    public function store(StoreRoleRequest $request, Server $server, CreateRole $roles, PermissionService $permissions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $below = $server->isOwnedBy($user) ? null : $permissions->highestPosition($user, $server);

        $role = $roles->create($server, $request->roleName(), $request->color(), $request->permissionMask(), $below);

        return redirect()
            ->route('roles.edit', [$server, $role])
            ->with('status', "The role \"{$role->name}\" has been created.");
    }

    public function edit(Server $server, Role $role): View
    {
        Gate::authorize('manage', $role);

        $holders = $role->users()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all();

        $members = $server->memberships()
            ->with('user')
            ->get()
            ->sortBy(fn (ServerMember $member): string => mb_strtolower($member->displayName()))
            ->values();

        return view('roles.edit', [
            'server' => $server,
            'role' => $role,
            'permissions' => Permission::cases(),
            'holders' => $members->filter(fn (ServerMember $member): bool => in_array((int) $member->user_id, $holders, true))->values(),
            'candidates' => $members->reject(fn (ServerMember $member): bool => in_array((int) $member->user_id, $holders, true))->values(),
        ]);
    }

    /**
     * @everyone keeps its name, colour and position; only its permissions
     * change. StoreRoleRequest checks RolePolicy::manage.
     */
    public function update(StoreRoleRequest $request, Server $server, Role $role): RedirectResponse
    {
        $role->update($role->is_default
            ? ['permissions' => $request->permissionMask()]
            : [
                'name' => $request->roleName(),
                'color' => $request->color(),
                'permissions' => $request->permissionMask(),
            ]);

        return redirect()->route('roles.edit', [$server, $role])->with('status', 'Role saved.');
    }

    /**
     * Field `direction`: `up` or `down`, one place at a time. Both the role
     * and the one it swaps with must be below the mover's highest role.
     */
    public function move(Request $request, Server $server, Role $role, MoveRole $roles): RedirectResponse
    {
        Gate::authorize('manage', $role);

        $validated = $request->validate([
            'direction' => ['required', 'string', Rule::in([MoveRole::UP, MoveRole::DOWN])],
        ]);

        if ($role->is_default) {
            throw ValidationException::withMessages(['direction' => Role::EVERYONE.' is always the lowest role.']);
        }

        $neighbour = $roles->neighbour($role, $validated['direction']);
        if ($neighbour !== null && Gate::denies('manage', $neighbour)) {
            throw ValidationException::withMessages(['direction' => 'You can only move roles below your own highest role.']);
        }

        $roles->move($role, $validated['direction']);

        return redirect()->route('roles.index', $server);
    }

    /**
     * The role is taken from every member that had it (role_user cascades).
     */
    public function destroy(Server $server, Role $role): RedirectResponse
    {
        Gate::authorize('manage', $role);

        if ($role->is_default) {
            throw ValidationException::withMessages(['role' => 'The '.Role::EVERYONE.' role cannot be deleted.']);
        }

        $role->delete();

        return redirect()
            ->route('roles.index', $server)
            ->with('status', "The role \"{$role->name}\" has been deleted.");
    }
}
