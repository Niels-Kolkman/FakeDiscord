<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\ManageGroupConversation;
use App\Actions\Conversations\OpenDirectConversation;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Group DMs: create one from the friends page, rename it, add
 * friends and remove people (or leave). The rules live in
 * App\Actions\Conversations\ManageGroupConversation and ConversationPolicy.
 *
 * Every action is a plain form that redirects with a status message, so
 * the pages work without JavaScript; JSON requests get a small JSON answer.
 */
class GroupConversationController extends Controller
{
    /**
     * Like Discord, picking a single friend opens your 1:1 DM with them
     * instead of a group of two.
     */
    public function store(Request $request, ManageGroupConversation $groups, OpenDirectConversation $direct): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(self::userIdsRules() + [
            'name' => ['nullable', 'string', 'max:'.Conversation::NAME_MAX_LENGTH],
        ]);

        $viewer = $this->user($request);
        $ids = ManageGroupConversation::pickedIds($viewer, $request->collect('user_ids')->all());

        $single = count($ids) === 1 ? User::query()->find($ids[0]) : null;
        if ($single !== null && in_array($ids[0], ManageGroupConversation::friendIds($viewer), true)) {
            $conversation = $direct->open($viewer, $single);
        } else {
            $conversation = $groups->create($viewer, $ids, $validated['name'] ?? null);
        }

        return $this->respond($request, $conversation, route('dms.show', $conversation), null, 201);
    }

    /**
     * Rename; an empty name goes back to the names of the people in it.
     */
    public function update(Request $request, Conversation $conversation, ManageGroupConversation $groups): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $conversation);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:'.Conversation::NAME_MAX_LENGTH],
        ]);

        $groups->rename($conversation, $validated['name'] ?? null);

        return $this->respond($request, $conversation, route('dms.show', $conversation), __('Group renamed.'));
    }

    public function addParticipants(Request $request, Conversation $conversation, ManageGroupConversation $groups): JsonResponse|RedirectResponse
    {
        Gate::authorize('addParticipants', $conversation);

        $request->validate(self::userIdsRules());
        $added = $groups->add($this->user($request), $conversation, $request->collect('user_ids')->all());

        return $this->respond($request, $conversation, route('dms.show', $conversation),
            $added === 0 ? null : trans_choice('{1} Added 1 person.|[2,*] Added :count people.', $added, ['count' => $added]));
    }

    /**
     * The owner removing someone, or anyone removing themselves (leaving).
     * Someone who is not in the group is a 404 (scoped binding).
     */
    public function removeParticipant(Request $request, Conversation $conversation, User $user, ManageGroupConversation $groups): JsonResponse|RedirectResponse
    {
        Gate::authorize('removeParticipant', [$conversation, $user]);

        $viewer = $this->user($request);
        $name = $conversation->load('users')->displayName($viewer);
        $groups->remove($conversation, $user);

        if ($user->is($viewer)) {
            return $this->respond($request, $conversation, route('friends.index'), __('You left :name.', ['name' => $name]));
        }

        return $this->respond($request, $conversation, route('dms.show', $conversation),
            __(':user was removed from the group.', ['user' => $user->name]));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function userIdsRules(): array
    {
        return [
            // Generous: the seat limit gives the friendlier error.
            'user_ids' => ['required', 'array', 'min:1', 'max:'.(Conversation::MAX_PARTICIPANTS * 2)],
            'user_ids.*' => ['integer'],
        ];
    }

    private function respond(Request $request, Conversation $conversation, string $url, ?string $status, int $code = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'conversation_id' => (int) $conversation->getKey(),
                'url' => $url,
                'status' => $status,
            ], $code);
        }

        $redirect = redirect()->to($url);

        return $status === null ? $redirect : $redirect->with('status', $status);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
