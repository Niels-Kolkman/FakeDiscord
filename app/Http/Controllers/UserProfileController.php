<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Public profile card data shown in the profile popover. Only fields that are
 * safe for any signed-in user to see are exposed (no email address).
 */
class UserProfileController extends Controller
{
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'name' => $user->name,
            'username' => $user->username,
            'bio' => $user->bio,
            'status' => $user->status,
            'avatar_url' => $user->avatarUrl(),
            'member_since' => $user->created_at?->toIso8601String(),
        ]);
    }
}
