/**
 * Kicks, bans and timeouts, live (ISSUE-29).
 *
 * On every page of a signed-in user (<meta name="user-id">) this listens on
 * the private "App.Models.User.{id}" channel for App\Events\MemberModerated
 * (".member.moderated"). Only the page of that server reacts; the channel
 * sidebar carries its id in [data-channel-sidebar][data-server-id].
 *
 * - "kicked" / "banned": leave the message panel's live channels
 *   ([data-channel-messages] data-broadcast-channel / data-typing-channel),
 *   remove the panel and show a notice with a link to the dashboard. The
 *   server also closes the user's sockets (App\Support\RealtimeSessions);
 *   when pusher-js reconnects, the server's channels refuse them.
 * - "timed_out" / "timeout_removed": reload, so the composer and reaction
 *   buttons match what the user may do now.
 *
 * The server name is inserted with textContent, never parsed as HTML.
 * initModeration() takes its echo, document and location as options so
 * tests/js/moderation.test.js can drive it.
 */

export const MODERATION_EVENT = '.member.moderated';

const REMOVED = ['kicked', 'banned'];
const CHANGED = ['timed_out', 'timeout_removed'];

/** The id of the server whose page is open, or null. */
export function currentServerId(document) {
    const id = Number(document.querySelector('[data-channel-sidebar]')?.dataset?.serverId);

    return Number.isInteger(id) && id > 0 ? id : null;
}

/** What the open page should do: "leave", "reload" or null (another server, unknown type). */
export function actionFor(event, serverId) {
    if (serverId === null || Number(event?.server?.id) !== serverId) return null;
    if (REMOVED.includes(event.type)) return 'leave';
    if (CHANGED.includes(event.type)) return 'reload';

    return null;
}

export function noticeText(event) {
    const name = typeof event?.server?.name === 'string' && event.server.name !== '' ? event.server.name : 'this server';

    return event?.type === 'banned' ? `You have been banned from ${name}.` : `You have been kicked from ${name}.`;
}

/** The live channels the message panel listens on. */
export function pageChannels(document) {
    const panel = document.querySelector('[data-channel-messages]');
    if (!panel) return [];

    return [panel.dataset.broadcastChannel, panel.dataset.typingChannel]
        .filter((name) => typeof name === 'string' && name !== '');
}

export function showRemovedNotice(document, { text, dashboardUrl }) {
    const notice = document.createElement('div');
    notice.dataset.moderationNotice = '';
    notice.setAttribute('role', 'alertdialog');
    notice.setAttribute('aria-live', 'assertive');
    notice.className = 'fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-6';

    const box = document.createElement('div');
    box.className = 'max-w-sm rounded-md bg-surface-2 p-6 text-center shadow-xl';

    const message = document.createElement('p');
    message.className = 'mb-4 text-fg-strong';
    message.textContent = text;
    box.appendChild(message);

    const link = document.createElement('a');
    link.href = dashboardUrl;
    link.className = 'inline-block rounded bg-[#5865f2] px-4 py-2 font-medium text-white hover:bg-[#4752c4]';
    link.textContent = 'Back to dashboard';
    box.appendChild(link);

    notice.appendChild(box);
    document.body.appendChild(notice);

    return notice;
}

/**
 * options: { echo, userId, document, location, dashboardUrl }
 * Returns the event handler (for tests), or null without Echo or a user.
 */
export function initModeration({ echo, userId, document, location, dashboardUrl = '/dashboard' }) {
    if (!echo || !userId) return null;

    const handle = (event) => {
        // Every page is in the server's presence channel (presence.js,
        // ISSUE-31), so leave it wherever the user is.
        const removedFrom = Number(event?.server?.id);
        if (REMOVED.includes(event?.type) && Number.isInteger(removedFrom) && removedFrom > 0) {
            echo.leave(`server.${removedFrom}`);
        }

        const action = actionFor(event, currentServerId(document));

        if (action === 'reload') {
            location.reload();
        } else if (action === 'leave') {
            pageChannels(document).forEach((name) => echo.leave(name));
            document.querySelector('[data-channel-messages]')?.remove();
            showRemovedNotice(document, { text: noticeText(event), dashboardUrl });
        }

        return action;
    };

    echo.private(`App.Models.User.${userId}`).listen(MODERATION_EVENT, handle);

    return handle;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    initModeration({
        echo: window.Echo,
        userId: document.querySelector('meta[name="user-id"]')?.getAttribute('content') || null,
        document,
        location: window.location,
    });
}
