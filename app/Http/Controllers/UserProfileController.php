<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public profile card data shown in the profile popover. Only fields that are
 * safe for any signed-in user to see are exposed (no email address).
 *
 * Blocking (ISSUE-25): `blocked` says whether the viewer blocked this user,
 * with the URLs the popover's Block / Unblock button posts to. Whether this
 * user blocked the viewer is never exposed.
 */
class UserProfileController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $isSelf = $user->is($viewer);

        $block = $isSelf ? null : Friendship::query()
            ->where('user_id', $viewer->getKey())
            ->where('friend_id', $user->getKey())
            ->where('status', Friendship::STATUS_BLOCKED)
            ->first();

        return response()->json([
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'username' => $user->username,
            'bio' => $user->bio,
            'status' => $user->status,
            'avatar_url' => $user->avatarUrl(),
            'member_since' => $user->created_at?->toIso8601String(),
            'is_self' => $isSelf,
            'blocked' => $block !== null,
            'block_url' => $isSelf ? null : route('friends.block'),
            'unblock_url' => $block === null ? null : route('friends.unblock', $block),
        ]);
    }
}
