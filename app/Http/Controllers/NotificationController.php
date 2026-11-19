<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\NotificationInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * The notification inbox (ISSUE-33, App\Support\NotificationInbox).
 *
 * resources/js/notifications.js opens it as a panel from the bell (JSON);
 * without JavaScript the bell is a link to the notifications page. Each
 * entry links to open(), which marks it read and goes to the message.
 * Only your own notifications can be read or opened: anyone else's id is
 * a 404.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        /** @var User $user */
        $user = $request->user();

        // items() first: it drops stale entries, which the count then skips.
        $items = NotificationInbox::items($user);
        $unread = NotificationInbox::unreadCount($user);

        if ($request->expectsJson()) {
            return response()->json(['notifications' => $items, 'unread_count' => $unread]);
        }

        return view('notifications.index', ['notifications' => $items, 'unreadCount' => $unread]);
    }

    /** Mark it read and jump to the message. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $found = $this->find($request, $notification);
        $found->markAsRead();

        return redirect()->to(NotificationInbox::present((string) $found->id, (array) $found->data, null, null, null)['url']);
    }

    public function read(Request $request, string $notification): JsonResponse|RedirectResponse
    {
        $this->find($request, $notification)->markAsRead();

        return $this->done($request);
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        NotificationInbox::markAllRead($user);

        return $this->done($request);
    }

    private function find(Request $request, string $id): DatabaseNotification
    {
        /** @var User $user */
        $user = $request->user();

        /** @var DatabaseNotification */
        return NotificationInbox::query($user)->whereKey($id)->firstOrFail();
    }

    private function done(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->expectsJson()) {
            return response()->json(['unread_count' => NotificationInbox::unreadCount($user)]);
        }

        return back(fallback: route('notifications.index'));
    }
}
