<?php

namespace App\Support;

use App\Models\ServerMember;
use Illuminate\Support\Collection;

/**
 * One heading of the member sidebar: a role, "Online" or "Offline".
 */
final readonly class MemberGroup
{
    public const ONLINE = 'online';

    public const OFFLINE = 'offline';

    /**
     * @param  string  $key  "role-{id}", "online" or "offline"
     * @param  Collection<int, ServerMember>  $members  sorted by display name
     */
    public function __construct(
        public string $key,
        public string $label,
        public Collection $members,
    ) {}
}
