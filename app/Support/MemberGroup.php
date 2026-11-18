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
     * The role's colour, only when it is a #rrggbb value (role colours
     * end up in a style attribute); null for "Online" and "Offline".
     */
    public ?string $color;

    /**
     * @param  string  $key  "role-{id}", "online" or "offline"
     * @param  Collection<int, ServerMember>  $members  sorted by display name
     */
    public function __construct(
        public string $key,
        public string $label,
        public Collection $members,
        ?string $color = null,
    ) {
        $this->color = is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : null;
    }
}
