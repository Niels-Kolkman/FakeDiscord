<?php

namespace App\Http\Controllers;

use App\Actions\Friends\ManageFriendship;
use App\Actions\Friends\SendFriendRequest;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Friends (ISSUE-22): the friends page with its All / Pending / Blocked
 * tabs, sending a request by username, and accepting, declining,
 * cancelling, removing and unblocking (App\Policies\FriendshipPolicy).
 *
 * The page works without JavaScript: every action is a form that
 * redirects to its tab with a status message. With Accept: JSON the
 * actions answer {friendship_id, changed, status} instead.
 * resources/js/friends.js reloads the lists live when the other person
 * acts (App\Events\FriendshipChanged).
 */
class FriendController extends Controller
{
    public const TABS = ['all', 'pending', 'blocked'];

    public function index(Request $request): View
    {
        $user = $this->user($request);

        $tab = $request->query('tab');
        $tab = is_string($tab) && in_array($tab, self::TABS, true) ? $tab : 'all';

        return view('friends.index', [
            'tab' => $tab,
            'incomingCount' => Friendship::incomingCount($user),
            'friends' => $tab === 'all' ? $this->friends($user) : collect(),
            'incoming' => $tab === 'pending' ? $this->pending($user, incoming: true) : collect(),
            'outgoing' => $tab === 'pending' ? $this->pending($user, incoming: false) : collect(),
            'blocked' => $tab === 'blocked' ? $this->blocked($user) : collect(),
        ]);
    }

    public function store(Request $request, SendFriendRequest $action): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64'],
        ], [
            'username.required' => __('Enter a username.'),
        ]);

        $user = $this->user($request);

        $friendship = $action->send($user, $validated['username']);
        $name = $friendship->otherUser($user)->name;

        return $friendship->isAccepted()
            ? $this->respond($request, $friendship, true, __('You and :name are now friends.', ['name' => $name]), 'all')
            : $this->respond($request, $friendship, true, __('Friend request sent to :name.', ['name' => $name]), 'pending');
    }

    public function accept(Request $request, Friendship $friendship, ManageFriendship $action): JsonResponse|RedirectResponse
    {
        Gate::authorize('accept', $friendship);
        $user = $this->user($request);

        return $this->respond($request, $friendship, $action->accept($user, $friendship),
            __('You and :name are now friends.', ['name' => $friendship->otherUser($user)->name]), 'pending');
    }

    public function decline(Request $request, Friendship $friendship, ManageFriendship $action): JsonResponse|RedirectResponse
    {
        Gate::authorize('decline', $friendship);
        $user = $this->user($request);

        return $this->respond($request, $friendship, $action->decline($user, $friendship),
            __('Friend request from :name declined.', ['name' => $friendship->otherUser($user)->name]), 'pending');
    }

    public function cancel(Request $request, Friendship $friendship, ManageFriendship $action): JsonResponse|RedirectResponse
    {
        Gate::authorize('cancel', $friendship);
        $user = $this->user($request);

        return $this->respond($request, $friendship, $action->cancel($user, $friendship),
            __('Friend request to :name cancelled.', ['name' => $friendship->otherUser($user)->name]), 'pending');
    }

    public function destroy(Request $request, Friendship $friendship, ManageFriendship $action): JsonResponse|RedirectResponse
    {
        Gate::authorize('remove', $friendship);
        $user = $this->user($request);

        return $this->respond($request, $friendship, $action->remove($user, $friendship),
            __(':name was removed from your friends.', ['name' => $friendship->otherUser($user)->name]), 'all');
    }

    public function unblock(Request $request, Friendship $friendship, ManageFriendship $action): JsonResponse|RedirectResponse
    {
        Gate::authorize('unblock', $friendship);
        $user = $this->user($request);

        return $this->respond($request, $friendship, $action->unblock($user, $friendship),
            __(':name is unblocked.', ['name' => $friendship->otherUser($user)->name]), 'blocked');
    }

    /**
     * Accepted friends, by name.
     *
     * @return Collection<int, array{friendship: Friendship, user: User}>
     */
    private function friends(User $user): Collection
    {
        $rows = Friendship::query()
            ->involving($user)
            ->where('status', Friendship::STATUS_ACCEPTED)
            ->with(['user', 'friend'])
            ->get();

        return $this->byName($this->rows($rows, $user));
    }

    /**
     * Requests waiting for this user's answer ($incoming) or that this user
     * sent, newest first.
     *
     * @return Collection<int, array{friendship: Friendship, user: User}>
     */
    private function pending(User $user, bool $incoming): Collection
    {
        $rows = ($incoming ? $user->receivedFriendships() : $user->sentFriendships())
            ->where('status', Friendship::STATUS_PENDING)
            ->with(['user', 'friend'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return $this->rows($rows, $user);
    }

    /**
     * People this user blocked, by name (blocking itself is ISSUE-25).
     * Being blocked by someone is never shown.
     *
     * @return Collection<int, array{friendship: Friendship, user: User}>
     */
    private function blocked(User $user): Collection
    {
        $rows = $user->sentFriendships()
            ->where('status', Friendship::STATUS_BLOCKED)
            ->with(['user', 'friend'])
            ->get();

        return $this->byName($this->rows($rows, $user));
    }

    /**
     * @param  iterable<Friendship>  $friendships
     * @return Collection<int, array{friendship: Friendship, user: User}>
     */
    private function rows(iterable $friendships, User $viewer): Collection
    {
        return collect($friendships)
            ->map(fn (Friendship $friendship): array => ['friendship' => $friendship, 'user' => $friendship->otherUser($viewer)])
            ->values();
    }

    /**
     * @param  Collection<int, array{friendship: Friendship, user: User}>  $rows
     * @return Collection<int, array{friendship: Friendship, user: User}>
     */
    private function byName(Collection $rows): Collection
    {
        return $rows->sortBy(fn (array $row): string => mb_strtolower($row['user']->name).' '.$row['user']->username)->values();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function respond(Request $request, Friendship $friendship, bool $changed, string $status, string $tab): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'friendship_id' => (int) $friendship->getKey(),
                'changed' => $changed,
                'status' => $changed ? $status : null,
            ]);
        }

        $redirect = redirect()->route('friends.index', ['tab' => $tab]);

        return $changed ? $redirect->with('status', $status) : $redirect;
    }
}
