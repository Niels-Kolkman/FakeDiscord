/**
 * Channel messages (resources/views/servers/show.blade.php).
 *
 * - The composer posts JSON to POST /servers/{id}/channels/{channel}/messages
 *   and appends the stored message from the response. Enter sends,
 *   Shift+Enter adds a new line.
 * - Messages from others arrive over Reverb: App\Events\MessageSent on the
 *   private "channel.{id}" channel (authorised in routes/channels.php).
 *   The server skips the sender's socket (X-Socket-ID), and messages are
 *   de-duplicated by id in case one arrives both ways.
 * - The list sticks to the bottom when a message arrives, unless you have
 *   scrolled up to read older ones.
 * - History (ISSUE-15): close to the top, the next older page is fetched
 *   from GET data-history-url?before={cursor} (MessageController::index)
 *   and prepended. The scroll position is kept by restoring the distance
 *   from the bottom, so what you were reading stays where it was. When
 *   next_cursor is null the "start of the channel" header is shown.
 * - "Jump to latest" appears once you are more than a screen away from the
 *   bottom, and counts the messages that arrived meanwhile.
 *
 * All user-provided text is inserted with textContent, never parsed as HTML.
 * renderMessage() must match resources/views/messages/item.blade.php.
 */

const NEAR_BOTTOM_PX = 120;
const LOAD_OLDER_PX = 400;

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
}

export function formatTime(iso) {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}

function renderAvatar(author) {
    if (!author) {
        const placeholder = el('span', 'mt-0.5 h-10 w-10 shrink-0 rounded-full bg-[#4e5058]');
        placeholder.setAttribute('aria-hidden', 'true');
        return placeholder;
    }

    // Same as <x-user-avatar>; profile-popover.js handles the click.
    const button = el('button', 'mt-0.5 inline-block shrink-0 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]');
    button.type = 'button';
    button.dataset.profileUrl = author.profile_url;
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', `View profile of ${author.name}`);

    const img = el('img', 'rounded-full object-cover');
    img.src = author.avatar_url;
    img.alt = '';
    img.width = 40;
    img.height = 40;
    img.style.width = '40px';
    img.style.height = '40px';
    button.appendChild(img);

    return button;
}

export function renderMessage(message) {
    const item = el('li', 'flex gap-4 px-4 py-1 hover:bg-[#2e3035]');
    item.dataset.message = '';
    item.dataset.messageId = String(message.id);

    item.appendChild(renderAvatar(message.author));

    const body = el('div', 'min-w-0 flex-1');
    const header = el('p', 'flex items-baseline gap-2');
    const author = el('span', 'truncate font-medium text-white', message.author?.name ?? 'Deleted User');
    author.dataset.messageAuthor = '';
    header.appendChild(author);

    const time = el('time', 'shrink-0 text-xs text-[#949ba4]', formatTime(message.created_at));
    time.dateTime = message.created_at ?? '';
    time.dataset.localTime = '';
    header.appendChild(time);
    body.appendChild(header);

    const content = el('p', 'whitespace-pre-wrap break-words text-[#dbdee1]', message.content);
    content.dataset.messageContent = '';
    body.appendChild(content);

    item.appendChild(body);
    return item;
}

function errorMessage(error) {
    const response = error.response;
    if (response?.status === 429) return 'You are sending messages too quickly. Wait a moment and try again.';
    if (response?.status === 403) return 'You cannot send messages in this channel.';
    return response?.data?.errors?.content?.[0] ?? 'Your message could not be sent. Try again.';
}

function initChannel(root) {
    const channelId = root.dataset.channelId;
    const list = root.querySelector('[data-message-list]');
    const scroller = root.querySelector('[data-message-scroller]');
    const form = root.querySelector('[data-message-form]');
    if (!list || !scroller) return;

    const seen = new Set([...list.querySelectorAll('[data-message]')].map((item) => item.dataset.messageId));

    // Server-rendered times are UTC; show them in the viewer's time zone.
    list.querySelectorAll('time[data-local-time]').forEach((time) => {
        time.textContent = formatTime(time.dateTime) || time.textContent;
    });

    const distanceFromBottom = () => scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight;
    const nearBottom = () => distanceFromBottom() < NEAR_BOTTOM_PX;
    const scrollToBottom = () => {
        scroller.scrollTop = scroller.scrollHeight;
    };

    // --- Jump to latest ------------------------------------------------
    const jump = root.querySelector('[data-jump-latest]');
    const jumpCount = root.querySelector('[data-jump-latest-count]');
    let unseen = 0;

    const updateJump = () => {
        if (!jump) return;
        const away = distanceFromBottom() > Math.max(scroller.clientHeight, NEAR_BOTTOM_PX);
        if (nearBottom()) unseen = 0;
        jump.hidden = !(away || unseen > 0);
        if (jumpCount) jumpCount.textContent = unseen > 0 ? `(${unseen} new)` : '';
    };

    jump?.addEventListener('click', () => {
        scroller.scrollTo({ top: scroller.scrollHeight, behavior: 'smooth' });
        unseen = 0;
        updateJump();
        root.querySelector('[data-message-input]')?.focus({ preventScroll: true });
    });

    // --- Older messages --------------------------------------------------
    const historyUrl = root.dataset.historyUrl;
    const start = root.querySelector('[data-channel-start]');
    const status = root.querySelector('[data-history-status]');
    const statusLoading = root.querySelector('[data-history-loading]');
    const statusError = root.querySelector('[data-history-error]');
    let olderCursor = root.dataset.olderCursor || null;
    let loadingOlder = false;
    let failed = false;

    // Everything that grows or shrinks above the viewport goes through
    // here: keep the distance from the bottom, so the messages on screen
    // stay where they are (the scroller has overflow-anchor: none).
    const keepPosition = (change) => {
        const fromBottom = scroller.scrollHeight - scroller.scrollTop;
        change();
        scroller.scrollTop = scroller.scrollHeight - fromBottom;
    };

    const showStatus = (state) => {
        if (!status) return;
        status.hidden = state === null;
        if (statusLoading) statusLoading.hidden = state !== 'loading';
        if (statusError) statusError.hidden = state !== 'error';
    };

    const prepend = (messages) => {
        const fragment = document.createDocumentFragment();
        messages.forEach((message) => {
            const id = String(message.id);
            if (seen.has(id)) return;
            seen.add(id);
            fragment.appendChild(renderMessage(message));
        });
        list.insertBefore(fragment, list.firstChild);
    };

    const loadOlder = () => {
        if (!historyUrl || !olderCursor || loadingOlder || failed) return;

        loadingOlder = true;
        keepPosition(() => showStatus('loading'));

        window.axios
            .get(historyUrl, { params: { before: olderCursor }, headers: { Accept: 'application/json' } })
            .then((response) => {
                olderCursor = response.data.next_cursor ?? null;
                keepPosition(() => {
                    showStatus(null);
                    prepend(response.data.messages ?? []);
                    if (!olderCursor && start) start.hidden = false;
                });
            })
            .catch(() => {
                failed = true;
                keepPosition(() => showStatus('error'));
            })
            .finally(() => {
                loadingOlder = false;
                // A short page may not fill the screen: keep going.
                if (!failed) requestAnimationFrame(maybeLoadOlder);
            });
    };

    const maybeLoadOlder = () => {
        if (scroller.scrollTop < LOAD_OLDER_PX) loadOlder();
    };

    root.querySelector('[data-history-retry]')?.addEventListener('click', () => {
        failed = false;
        loadOlder();
    });

    scroller.addEventListener('scroll', () => {
        maybeLoadOlder();
        updateJump();
    }, { passive: true });

    scrollToBottom();
    updateJump();
    maybeLoadOlder();

    const append = (message, own = false) => {
        if (!message || String(message.channel_id) !== channelId) return;
        const id = String(message.id);
        if (seen.has(id)) return;

        const stick = own || nearBottom();
        seen.add(id);
        list.appendChild(renderMessage(message));
        if (stick) {
            scrollToBottom();
        } else {
            unseen += 1;
        }
        updateJump();
    };

    window.Echo?.private(`channel.${channelId}`).listen('.message.sent', (message) => append(message));

    if (!form) return;

    const input = form.querySelector('[data-message-input]');
    const errorBox = form.querySelector('[data-message-error]');
    let sending = false;

    const showError = (text) => {
        errorBox.textContent = text ?? '';
        errorBox.classList.toggle('hidden', !text);
    };

    const resize = () => {
        input.style.height = 'auto';
        input.style.height = `${input.scrollHeight}px`;
    };

    input.addEventListener('input', resize);

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const content = input.value.trim();
        if (!content || sending) return;

        sending = true;
        const socketId = window.Echo?.socketId();

        window.axios
            .post(form.action, { content }, {
                headers: socketId ? { 'X-Socket-ID': socketId } : {},
            })
            .then((response) => {
                append(response.data.message, true);
                input.value = '';
                resize();
                showError(null);
            })
            .catch((error) => showError(errorMessage(error)))
            .finally(() => {
                sending = false;
                input.focus();
            });
    });
}

document.querySelectorAll('[data-channel-messages]').forEach(initChannel);
