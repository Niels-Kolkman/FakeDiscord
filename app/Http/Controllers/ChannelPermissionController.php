<?php

namespace App\Http\Controllers;

use App\Actions\Channels\SetChannelOverride;
use App\Enums\Permission;
use App\Http\Requests\UpdateChannelOverrideRequest;
use App\Models\Channel;
use App\Models\ChannelPermissionOverride;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A channel's permissions: make it private and allow or deny
 * View Channels and Send Messages per role.
 *
 * Every route needs Manage Channels and the channel must be one the member
 * can see. Changing a role's override also needs RolePolicy::manage (Manage
 * Roles, and the role below the member's highest role), and a flag can only
 * be allowed or denied by someone who has it in the channel themselves, so
 * nobody hands out more than they have.
 */
class ChannelPermissionController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function edit(Request $request, Server $server, Channel $channel): View
    {
        $this->authorizeChannel($server, $channel);

        /** @var User $user */
        $user = $request->user();
        $roles = $server->rolesInOrder()->get();
        $overrides = $channel->permissionOverrides()->get()->keyBy('role_id');

        return view('channels.permissions', [
            'server' => $server,
            'channel' => $channel,
            'roles' => $roles,
            'overrides' => $overrides,
            'flags' => Permission::channelOverridable(),
            'manageable' => $roles
                ->filter(fn (Role $role): bool => $user->can('manage', $role))
                ->modelKeys(),
        ]);
    }

    /**
     * Field `is_private` (boolean).
     */
    public function updatePrivacy(Request $request, Server $server, Channel $channel): RedirectResponse
    {
        $this->authorizeChannel($server, $channel);

        $validated = $request->validate(['is_private' => ['required', 'boolean']]);
        $channel->update(['is_private' => (bool) $validated['is_private']]);

        return redirect()
            ->route('channels.permissions.edit', [$server, $channel])
            ->with('status', $channel->is_private
                ? "#{$channel->name} is now private."
                : "#{$channel->name} is now visible to everyone who can view channels.");
    }

    public function updateRole(UpdateChannelOverrideRequest $request, Server $server, Channel $channel, Role $role, SetChannelOverride $overrides): RedirectResponse
    {
        // The route is not scope-bound ({role} has no relation on Channel),
        // so both are checked here: another server's role is a 404.
        abort_unless((int) $role->server_id === (int) $server->getKey(), 404);
        $this->authorizeChannel($server, $channel);
        Gate::authorize('manage', $role);

        /** @var User $user */
        $user = $request->user();
        $states = $request->states();
        $current = $channel->permissionOverrides()->where('role_id', $role->getKey())->first();

        foreach (Permission::channelOverridable() as $permission) {
            $was = $current?->stateOf($permission) ?? ChannelPermissionOverride::INHERIT;

            if ($states[$permission->name] !== $was && ! $this->permissions->hasInChannel($user, $channel, $permission)) {
                throw ValidationException::withMessages([
                    "permissions.{$permission->name}" => "You can only change {$permission->label()} if you have it in this channel.",
                ]);
            }
        }

        $overrides->set($channel, $role, $states);

        return redirect()
            ->route('channels.permissions.edit', [$server, $channel])
            ->with('status', "Permissions for \"{$role->name}\" in #{$channel->name} saved.");
    }

    private function authorizeChannel(Server $server, Channel $channel): void
    {
        abort_unless((int) $channel->server_id === (int) $server->getKey(), 404);
        Gate::authorize('manageChannels', $server);
        Gate::authorize('view', $channel);
    }
}
