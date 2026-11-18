<?php

namespace App\Actions\Servers;

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a server with everything a new server needs: the creator as owner
 * and first member, the implicit @everyone role and a #general text channel.
 */
class CreateServer
{
    public const DEFAULT_CHANNEL = 'general';

    public function __construct(private readonly UpdateServerIcon $icons) {}

    /**
     * @throws ValidationException when the icon cannot be decoded
     */
    public function create(User $owner, string $name, ?UploadedFile $icon = null): Server
    {
        // The icon is stored inside the transaction so an unreadable image
        // leaves no half-created server behind.
        return DB::transaction(function () use ($owner, $name, $icon): Server {
            $server = Server::create(['owner_id' => $owner->getKey(), 'name' => $name]);

            $server->memberships()->create([
                'user_id' => $owner->getKey(),
                'joined_at' => now(),
            ]);

            $server->roles()->create([
                'name' => Role::EVERYONE,
                'position' => 0,
                'permissions' => Permission::everyoneDefaults(),
                'is_default' => true,
            ]);

            $server->channels()->create([
                'name' => self::DEFAULT_CHANNEL,
                'type' => Channel::TYPE_TEXT,
                'position' => 0,
            ]);

            if ($icon !== null) {
                $this->icons->update($server, $icon);
            }

            return $server;
        });
    }
}
