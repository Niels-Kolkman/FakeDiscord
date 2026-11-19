/**
 * Browser notifications (ISSUE-34), with the Web Notifications API.
 *
 * - What: a mention (App\Notifications\MentionedInMessage, Echo's
 *   .notification() on "App.Models.User.{id}") and a DM or group DM
 *   message (App\Events\DirectMessageReceived, ".dm.received" on the same
 *   channel).
 * - When: only while this tab is not in focus (hidden, or another window
 *   is active), only once the user allowed notifications for the site,
 *   not when they turned them off in this browser (localStorage, on the
 *   settings page), not while their status is Do Not Disturb, and not for
 *   mentions from a server or channel they muted (the broadcast says
 *   `push: false`, App\Support\NotificationMutes).
 * - Each notification has a tag (one per mention / message), so several
 *   open tabs show it once. Clicking it focuses the window and opens the
 *   message.
 *
 * initPushSettings() drives the switch on the settings page
 * ([data-push-settings], settings/notifications.blade.php): browsers only
 * ask for permission after a click.
 *
 * Both take their browser APIs as options, so
 * tests/js/push-notifications.test.js can drive them.
 */

export const MENTION_TYPE = 'mention.created';
export const DM_EVENT = '.dm.received';
export const OPT_OUT_KEY = 'fakediscord.push-notifications-off';
const BODY_LENGTH = 140;

function safeGet(storage, key) {
    try {
        return storage?.getItem(key) ?? null;
    } catch {
        return null;
    }
}

function safeSet(storage, key, value) {
    try {
        if (value === null) storage?.removeItem(key);
        else storage?.setItem(key, value);
    } catch {
        // Private windows may refuse; the switch just does not stick.
    }
}

/**
 * 'unsupported' (no Notification API), 'default' (never asked),
 * 'denied' (blocked in the browser), 'off' (allowed, turned off here) or
 * 'on'.
 */
export function pushState(NotificationApi, storage) {
    if (!NotificationApi) return 'unsupported';
    if (NotificationApi.permission === 'denied') return 'denied';
    if (NotificationApi.permission !== 'granted') return 'default';
    return safeGet(storage, OPT_OUT_KEY) === '1' ? 'off' : 'on';
}

/** Hidden, or visible but another window (or app) has the focus. */
export function tabUnfocused(doc) {
    if (!doc) return false;
    if (doc.visibilityState === 'hidden') return true;
    return typeof doc.hasFocus === 'function' ? !doc.hasFocus() : false;
}

function trimBody(text) {
    const line = String(text ?? '').replace(/[ \t]+/g, ' ').trim();
    return line.length > BODY_LENGTH ? `${line.slice(0, BODY_LENGTH - 1).trimEnd()}…` : line;
}

/**
 * What to show for a mention broadcast (NotificationInbox::present() plus
 * `push`), or null when it should not pop up.
 */
export function mentionNotification(payload) {
    if (!payload || payload.type !== MENTION_TYPE || payload.push === false) return null;

    const author = payload.author_name || 'Someone';
    const label = payload.label || 'mentioned you';
    const where = [payload.server_name, payload.channel_name ? `#${payload.channel_name}` : '']
        .filter(Boolean)
        .join(' · ');

    return {
        title: `${author} ${label}`,
        options: {
            body: trimBody([where, payload.excerpt].filter(Boolean).join('\n')),
            icon: payload.avatar_url || undefined,
            tag: `mention-${payload.id ?? payload.message_id}`,
        },
        // open_url marks the inbox entry read on the way.
        url: payload.open_url || payload.url || null,
    };
}

/** What to show for a ".dm.received" event (DirectMessageReceived). */
export function dmNotification(payload) {
    if (!payload || payload.message_id == null) return null;

    const author = payload.author_name || 'Someone';
    const title = payload.is_group && payload.title ? `${author} in ${payload.title}` : author;
    const body = payload.excerpt || (payload.has_attachments ? 'Sent an attachment' : 'Sent a message');

    return {
        title,
        options: {
            body: trimBody(body),
            icon: payload.avatar_url || undefined,
            tag: `dm-${payload.message_id}`,
        },
        url: payload.url || null,
    };
}

/**
 * options: { echo, userId, document, Notification, storage,
 * getStatus() => 'online' | 'idle' | 'dnd', navigate(url), focus() }.
 * Returns a handle for tests, or null when nothing can be shown.
 */
export function initPushNotifications({
    echo,
    userId,
    document: doc,
    Notification: NotificationApi,
    storage,
    getStatus = () => 'online',
    navigate = () => {},
    focus = () => {},
}) {
    if (!NotificationApi || !echo || !userId) return null;

    const show = (spec) => {
        if (!spec) return null;
        if (pushState(NotificationApi, storage) !== 'on') return null;
        if (!tabUnfocused(doc)) return null;
        if (getStatus() === 'dnd') return null;

        let notification;
        try {
            notification = new NotificationApi(spec.title, spec.options);
        } catch {
            // Some browsers (Android Chrome) only allow them from a service worker.
            return null;
        }

        notification.onclick = (event) => {
            event?.preventDefault?.();
            focus();
            if (spec.url) navigate(spec.url);
            notification.close?.();
        };

        return notification;
    };

    const onMention = (payload) => show(mentionNotification(payload));
    const onDirectMessage = (payload) => show(dmNotification(payload));

    echo.private(`App.Models.User.${userId}`)
        .notification(onMention)
        .listen(DM_EVENT, onDirectMessage);

    return { onMention, onDirectMessage, show };
}

const SETTINGS_TEXT = {
    unsupported: ['This browser does not support desktop notifications.', null],
    default: ['Desktop notifications are off. Your browser will ask you to allow them.', 'Enable desktop notifications'],
    denied: ['Desktop notifications are blocked for this site. Allow them in your browser\'s site settings.', null],
    off: ['Desktop notifications are turned off in this browser.', 'Turn on desktop notifications'],
    on: ['Desktop notifications are on in this browser.', 'Turn off desktop notifications'],
};

/**
 * The switch on the settings page. options: { document, Notification,
 * storage }. Returns a handle for tests, or null without the section.
 */
export function initPushSettings({ document: doc, Notification: NotificationApi, storage }) {
    const root = doc.querySelector('[data-push-settings]');
    if (!root) return null;

    const status = root.querySelector('[data-push-status]');
    const toggle = root.querySelector('[data-push-toggle]');

    const render = () => {
        const state = pushState(NotificationApi, storage);
        const [text, action] = SETTINGS_TEXT[state];
        if (status) status.textContent = text;
        if (toggle) {
            toggle.hidden = action === null;
            if (action !== null) toggle.textContent = action;
        }
        root.dataset.pushState = state;
        return state;
    };

    const click = async () => {
        const state = pushState(NotificationApi, storage);
        if (state === 'on') {
            safeSet(storage, OPT_OUT_KEY, '1');
        } else if (state === 'off') {
            safeSet(storage, OPT_OUT_KEY, null);
        } else if (state === 'default') {
            safeSet(storage, OPT_OUT_KEY, null);
            try {
                await NotificationApi.requestPermission();
            } catch {
                // Treated like a dismissed prompt.
            }
        }
        return render();
    };

    toggle?.addEventListener('click', () => {
        click();
    });

    render();

    return { render, click };
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    const storage = (() => {
        try {
            return window.localStorage;
        } catch {
            return null;
        }
    })();
    const NotificationApi = 'Notification' in window ? window.Notification : null;

    initPushSettings({ document, Notification: NotificationApi, storage });

    initPushNotifications({
        echo: window.Echo,
        userId: document.querySelector('meta[name="user-id"]')?.getAttribute('content') || null,
        document,
        Notification: NotificationApi,
        storage,
        // presence.js keeps this meta tag in step with the status picker.
        getStatus: () => document.querySelector('meta[name="user-status"]')?.getAttribute('content') ?? 'online',
        navigate: (url) => {
            window.location.href = url;
        },
        focus: () => window.focus(),
    });
}
