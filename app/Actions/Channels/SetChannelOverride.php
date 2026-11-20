<?php

namespace App\Actions\Channels;

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\ChannelPermissionOverride;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Allow, deny or inherit flags for one role in one channel.
 * Only Permission::channelOverridable() flags can be set; flags left out of
 * $states keep their current state. An override that ends up allowing and
 * denying nothing is deleted, so "all inherit" leaves no row behind.
 */
class SetChannelOverride
{
    /**
     * @param  array<string, string>  $states  Permission case name => ChannelPermissionOverride::ALLOW|DENY|INHERIT
     */
    public function set(Channel $channel, Role $role, array $states): ?ChannelPermissionOverride
    {
        if ((int) $role->server_id !== (int) $channel->server_id) {
            throw new InvalidArgumentException('The role does not belong to the channel\'s server.');
        }

        return DB::transaction(function () use ($channel, $role, $states): ?ChannelPermissionOverride {
            $override = $channel->permissionOverrides()->where('role_id', $role->getKey())->lockForUpdate()->first();
            $allow = $override === null ? 0 : (int) $override->allow;
            $deny = $override === null ? 0 : (int) $override->deny;

            foreach (Permission::channelOverridable() as $permission) {
                $state = $states[$permission->name] ?? null;
                if ($state === null) {
                    continue;
                }

                $allow &= ~$permission->value;
                $deny &= ~$permission->value;

                match ($state) {
                    ChannelPermissionOverride::ALLOW => $allow |= $permission->value,
                    ChannelPermissionOverride::DENY => $deny |= $permission->value,
                    ChannelPermissionOverride::INHERIT => null,
                    default => throw new InvalidArgumentException("Unknown override state [{$state}]."),
                };
            }

            if ($allow === 0 && $deny === 0) {
                $override?->delete();

                return null;
            }

            if ($override === null) {
                return $channel->permissionOverrides()->create([
                    'role_id' => $role->getKey(),
                    'allow' => $allow,
                    'deny' => $deny,
                ]);
            }

            $override->update(['allow' => $allow, 'deny' => $deny]);

            return $override;
        });
    }
}
