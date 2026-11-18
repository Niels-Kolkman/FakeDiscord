<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\ManageGroupConversation;
use App\Actions\Conversations\OpenDirectConversation;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\User;
use App\Support\MessageFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 1:1 direct messages (ISSUE-23): open the DM with someone ("Message" on
 * the friends page) and show a conversation with its newest messages.
 *
 * The conversation page reuses the channel's message panel
 * (messages/panel.blade.php and resources/js/channel-messages.js), with
 * the DM's own routes and its "conversation.{id}" broadcast channel.
 *
 * Only participants see a conversation (ConversationPolicy::view); anyone
 * else gets a 404. A group DM (ISSUE-24) opens on the same route with
 * conversations/group.blade.php: its people, Add friends, Rename and
 * Leave (GroupConversationController handles those forms).
 */
class DirectMessageController extends Controller
{
    /**
     * Open (or create) the DM with `user_id` and go there.
     */
    public function store(Request $request, OpenDirectConversation $action): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $viewer = $this->user($request);
        $other = User::query()->find($validated['user_id']);

        if ($other === null) {
            // Same message as for someone you cannot reach.
            throw ValidationException::withMessages(['user' => OpenDirectConversation::unreachable()]);
        }

        $conversation = $action->open($viewer, $other);
        $url = route('dms.show', $conversation);

        if ($request->expectsJson()) {
            return response()->json(['conversation_id' => (int) $conversation->getKey(), 'url' => $url]);
        }

        return redirect()->to($url);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        Gate::authorize('view', $conversation);

        $viewer = $this->user($request);
        $conversation->load('users');

        if ($conversation->isGroup()) {
            return view('conversations.group', [
                'conversation' => $conversation,
                'members' => $conversation->users
                    ->sortBy(fn (User $user): string => ($conversation->isOwnedBy($user) ? '0' : '1').mb_strtolower($user->name))
                    ->values(),
                'invitable' => ManageGroupConversation::invitableFriends($viewer, $conversation),
                'isOwner' => $conversation->isOwnedBy($viewer),
                'feed' => MessageFeed::for($conversation),
                'canSendMessages' => $viewer->can('sendMessage', $conversation),
                'incomingCount' => Friendship::incomingCount($viewer),
            ]);
        }

        abort_unless($conversation->isDirect(), 404);

        return view('conversations.show', [
            'conversation' => $conversation,
            'other' => $conversation->otherParticipant($viewer),
            'feed' => MessageFeed::for($conversation),
            'canSendMessages' => $viewer->can('sendMessage', $conversation),
            'incomingCount' => Friendship::incomingCount($viewer),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
