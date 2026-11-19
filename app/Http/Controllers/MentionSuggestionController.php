<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Channel;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Mentions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Mention autocomplete (ISSUE-33): what "@{q}" in the composer of a
 * channel could become, for resources/js/mention-autocomplete.js.
 *
 * - users: members who can see the channel whose username, server
 *   nickname or name starts with q (inserted as "@username");
 * - roles: the server's roles (not @everyone) whose name starts with q;
 * - everyone: whether "@everyone" matches q and the viewer may use it
 *   (Mention @everyone), so it would notify anyone.
 *
 * Only for members who can send in the channel. An empty q lists the
 * first few of each.
 */
class MentionSuggestionController extends Controller
{
    public const MAX_USERS = 8;

    public const MAX_ROLES = 5;

    public const MAX_QUERY_LENGTH = 100;

    public function index(Request $request, Server $server, Channel $channel, PermissionService $permissions): JsonResponse
    {
        Gate::authorize('view', $server);
        Gate::authorize('sendMessages', $channel);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:'.self::MAX_QUERY_LENGTH],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $query = mb_strtolower(trim((string) ($validated['q'] ?? '')));
        $prefix = self::likePrefix($query);

        $candidates = User::query()
            ->join('server_members', 'server_members.user_id', '=', 'users.id')
            ->where('server_members.server_id', $server->id)
            ->when($query !== '', fn (Builder $users) => $users->where(fn (Builder $match) => $match
                ->whereRaw("lower(users.username) like ? escape '\\'", [$prefix])
                ->orWhereRaw("lower(server_members.nickname) like ? escape '\\'", [$prefix])
                ->orWhereRaw("lower(users.name) like ? escape '\\'", [$prefix])))
            ->orderBy('users.username')
            ->limit(self::MAX_USERS * 3)
            ->get(['users.*', 'server_members.nickname as server_nickname']);

        $users = $candidates
            ->filter(fn (User $user): bool => $permissions->hasInChannel($user, $channel, Permission::ViewChannels))
            ->take(self::MAX_USERS)
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'name' => (string) ($user->getAttribute('server_nickname') ?? $user->name),
                'avatar_url' => $user->avatarUrl(),
            ])
            ->values()
            ->all();

        $roles = Role::query()
            ->where('server_id', $server->id)
            ->where('is_default', false)
            ->when($query !== '', fn (Builder $roles) => $roles->whereRaw("lower(name) like ? escape '\\'", [$prefix]))
            ->orderByDesc('position')
            ->limit(self::MAX_ROLES)
            ->get()
            ->map(fn (Role $role): array => [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
                'color' => $role->displayColor(),
            ])
            ->values()
            ->all();

        $everyone = str_starts_with(Mentions::EVERYONE, $query)
            && $permissions->hasInChannel($viewer, $channel, Permission::MentionEveryone);

        return response()->json([
            'users' => $users,
            'roles' => $roles,
            'everyone' => $everyone,
        ]);
    }

    /** "q%" for LIKE, with LIKE's wildcards in q taken literally. */
    private static function likePrefix(string $query): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
    }
}
