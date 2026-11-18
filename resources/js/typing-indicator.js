/**
 * Typing indicators (ISSUE-17), used by channel-messages.js.
 *
 * - Everyone viewing a text channel joins the presence channel
 *   "typing.{id}" (authorised in routes/channels.php; the member info is
 *   {id, name}, name being the server nickname or the user's name).
 * - While you type, a "client-typing" whisper {typing: true} goes out at
 *   most once every TYPING_THROTTLE_MS. Sending the message, or clearing
 *   the box, whispers {typing: false} so the indicator goes away at once.
 * - Receivers show "X is typing…" and drop a person TYPING_EXPIRE_MS after
 *   their last whisper (a closed tab or a lost {typing: false} cannot
 *   leave it stuck), when they leave the presence channel, or when their
 *   message arrives.
 * - Who is typing comes from the whisper's user_id, which Reverb adds
 *   itself on presence channels (accept_client_events_from = "members"),
 *   never from the payload, and must be a current presence member. Names
 *   come from the presence member info, not from the whisper.
 *
 * The pure parts (throttle, tracker, text) take their clock and timers as
 * options so tests/js/typing-indicator.test.js can drive them.
 */

export const TYPING_THROTTLE_MS = 3000;
export const TYPING_EXPIRE_MS = 5000;
export const TYPING_EVENT = 'typing';

/** More than this many people typing reads "Several people are typing…". */
const MAX_NAMES = 3;

/**
 * Calls send() on the first keystroke and then at most once per interval.
 * reset() makes the next keystroke send right away again.
 */
export function createTypingThrottle(send, { interval = TYPING_THROTTLE_MS, now = () => Date.now() } = {}) {
    let last = null;

    return {
        typed() {
            const time = now();
            if (last !== null && time - last < interval) return false;
            last = time;
            send();
            return true;
        },
        reset() {
            last = null;
        },
    };
}

/**
 * Who is typing right now, in the order they started. Each entry expires
 * expireMs after the last typing() for that id; onChange(names) runs
 * whenever the list changes.
 */
export class TypingTracker {
    constructor({
        expireMs = TYPING_EXPIRE_MS,
        onChange = () => {},
        setTimer = (callback, ms) => setTimeout(callback, ms),
        clearTimer = (id) => clearTimeout(id),
    } = {}) {
        this.expireMs = expireMs;
        this.onChange = onChange;
        this.setTimer = setTimer;
        this.clearTimer = clearTimer;
        /** @type {Map<string, {name: string, timer: unknown}>} */
        this.typists = new Map();
    }

    typing(id, name) {
        const key = String(id);
        const current = this.typists.get(key);
        if (current) this.clearTimer(current.timer);

        const timer = this.setTimer(() => this.stopped(key), this.expireMs);
        this.typists.set(key, { name, timer });

        if (!current || current.name !== name) this.onChange(this.names());
    }

    stopped(id) {
        const key = String(id);
        const current = this.typists.get(key);
        if (!current) return;
        this.clearTimer(current.timer);
        this.typists.delete(key);
        this.onChange(this.names());
    }

    clear() {
        if (this.typists.size === 0) return;
        this.typists.forEach(({ timer }) => this.clearTimer(timer));
        this.typists.clear();
        this.onChange(this.names());
    }

    names() {
        return [...this.typists.values()].map(({ name }) => name);
    }
}

/**
 * The indicator as segments, so names can be shown bold and inserted with
 * textContent: [{text, name: true|false}, ...]. Empty when nobody types.
 */
export function typingParts(names) {
    if (names.length === 0) return [];
    if (names.length > MAX_NAMES) return [{ text: 'Several people are typing…', name: false }];

    const parts = [];
    names.forEach((name, index) => {
        if (index > 0) parts.push({ text: index === names.length - 1 ? ' and ' : ', ', name: false });
        parts.push({ text: name, name: true });
    });
    parts.push({ text: names.length === 1 ? ' is typing…' : ' are typing…', name: false });

    return parts;
}

export function formatTyping(names) {
    return typingParts(names).map(({ text }) => text).join('');
}

/**
 * Wire one channel page up. Returns { messageArrived(authorId), sent() }
 * for channel-messages.js, or null without Echo.
 *
 * options: { echo, channelId, viewerId, input (textarea or null),
 *            output (element that shows the text),
 *            channelName (the presence channel; defaults to "typing.{channelId}",
 *            a DM passes "conversation-typing.{id}", ISSUE-23) }
 */
export function initTypingIndicator({ echo, channelId, viewerId, input, output, channelName = null }) {
    if (!echo || !output) return null;

    const members = new Map();
    const self = String(viewerId);

    const render = (names) => {
        output.replaceChildren(...typingParts(names).map(({ text, name }) => {
            if (!name) return document.createTextNode(text);
            const strong = document.createElement('strong');
            strong.className = 'font-semibold text-[#dbdee1]';
            strong.textContent = text;
            return strong;
        }));
    };

    const tracker = new TypingTracker({ onChange: render });

    const presence = echo.join(channelName || `typing.${channelId}`)
        .here((list) => {
            members.clear();
            list.forEach((member) => members.set(String(member.id), member));
        })
        .joining((member) => members.set(String(member.id), member))
        .leaving((member) => {
            members.delete(String(member.id));
            tracker.stopped(member.id);
        })
        .listenForWhisper(TYPING_EVENT, (data, metadata) => {
            const id = metadata?.user_id == null ? null : String(metadata.user_id);
            if (id === null || id === self) return;
            const member = members.get(id);
            if (!member) return;

            if (data?.typing === false) {
                tracker.stopped(id);
            } else {
                tracker.typing(id, String(member.name ?? ''));
            }
        });

    const whisper = (typing) => {
        try {
            presence.whisper(TYPING_EVENT, { typing });
        } catch {
            // Not subscribed (yet): the indicator is best effort.
        }
    };

    const throttle = createTypingThrottle(() => whisper(true));
    let announced = false;

    input?.addEventListener('input', () => {
        if (input.value.trim() === '') {
            if (announced) whisper(false);
            announced = false;
            throttle.reset();
            return;
        }
        if (throttle.typed()) announced = true;
    });

    return {
        // Their message is here, so they are done typing it.
        messageArrived(authorId) {
            if (authorId != null) tracker.stopped(authorId);
        },
        // You sent yours: tell the others now instead of letting it expire.
        sent() {
            if (announced) whisper(false);
            announced = false;
            throttle.reset();
        },
    };
}
