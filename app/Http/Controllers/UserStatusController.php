<?php

namespace App\Http\Controllers;

use App\Actions\Profile\UpdateUserStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The status picker (<x-status-picker>): online, idle or dnd.
 * A plain form redirects back; resources/js/presence.js sends it with
 * Accept: JSON and gets {status} instead.
 */
class UserStatusController extends Controller
{
    public function update(Request $request, UpdateUserStatus $statuses): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(User::ONLINE_STATUSES)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $statuses->update($user, $validated['status']);

        if ($request->expectsJson()) {
            return response()->json(['status' => $user->chosenStatus()]);
        }

        return back()->with('status', 'Your status is now '.User::STATUS_LABELS[$user->chosenStatus()].'.');
    }
}
