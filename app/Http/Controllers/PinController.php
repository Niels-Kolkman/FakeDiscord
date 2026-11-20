<?php

namespace App\Http\Controllers;

use App\Actions\Messages\PinMessage;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use App\Support\Reactions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Pinned messages: a channel's pinned list, and pinning or
 * unpinning a message (MessagePolicy::pin, moderators only).
 *
 * resources/js/channel-messages.js opens the list as a panel from the
 * channel header (JSON); without JavaScript the header link opens the
 * channels/pins page, and Pin / Unpin are forms that redirect back.
 *
 * Scoped bindings make a channel of another server, or a message of
 * another channel or a deleted one, a 404.
 */
class PinController extends Controller
{
    /**
     * Newest pin first. Deleted messages are left out (soft deletes).
     */
    public function index(Request $request, Server $server, Channel $channel): JsonResponse|View
    {
        Gate::authorize('view', $server);
        Gate::authorize('view', $channel);
        abort_if($channel->isVoice(), 404);

        /** @var User $user */
        $user = $request->user();

        $pins = $this->pins($channel, $user);

        if ($request->expectsJson()) {
            return response()->json([
                'pins' => $pins->map(fn (Message $message): array => self::payload($message, $user))->values()->all(),
            ]);
        }

        return view('channels.pins', [
            'server' => $server,
            'layout' => $server->channelLayout($user),
            'channel' => $channel,
            'pins' => $pins,
            'members' => $server->memberList(),
            'canManageMessages' => $user->can('manageMessages', $channel),
        ]);
    }

    public function store(Request $request, Server $server, Channel $channel, Message $message, PinMessage $pins): JsonResponse|RedirectResponse
    {
        Gate::authorize('pin', $message);

        /** @var User $user */
        $user = $request->user();

        $changed = $pins->pin($user, $message);

        return $this->respond($request, $user, $server, $channel, $message, $changed);
    }

    public function destroy(Request $request, Server $server, Channel $channel, Message $message, PinMessage $pins): JsonResponse|RedirectResponse
    {
        Gate::authorize('pin', $message);

        /** @var User $user */
        $user = $request->user();

        $changed = $pins->unpin($message);

        return $this->respond($request, $user, $server, $channel, $message, $changed);
    }

    /**
     * A message payload plus who pinned it.
     *
     * @return array<string, mixed>
     */
    public static function payload(Message $message, ?User $viewer = null): array
    {
        $by = $message->isPinned() ? $message->pinnedBy : null;

        return [
            ...$message->toPayload($viewer),
            'pinned_by' => $by === null ? null : ['id' => (int) $by->id, 'name' => $by->name],
        ];
    }

    /**
     * @return Collection<int, Message>
     */
    private function pins(Channel $channel, User $viewer): Collection
    {
        return $channel->messages()
            ->whereNotNull('pinned_at')
            // Not the messages of people the viewer blocked.
            ->visibleTo($viewer)
            ->with(['author', 'attachments', 'pinnedBy', ...Reactions::relations(), ...Message::replyRelations()])
            ->orderByDesc('pinned_at')
            ->orderByDesc('id')
            ->limit(Message::MAX_PINS_PER_CHANNEL)
            ->get();
    }

    private function respond(Request $request, User $user, Server $server, Channel $channel, Message $message, bool $changed): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => self::payload($message, $user),
                'changed' => $changed,
            ]);
        }

        return redirect()->back(fallback: route('channels.show', [$server, $channel]));
    }
}
