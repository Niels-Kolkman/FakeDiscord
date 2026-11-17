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
 *
 * All user-provided text is inserted with textContent, never parsed as HTML.
 * renderMessage() must match resources/views/messages/item.blade.php.
 */

const NEAR_BOTTOM_PX = 120;

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

    const nearBottom = () => scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < NEAR_BOTTOM_PX;
    const scrollToBottom = () => {
        scroller.scrollTop = scroller.scrollHeight;
    };
    scrollToBottom();

    const append = (message, own = false) => {
        if (!message || String(message.channel_id) !== channelId) return;
        const id = String(message.id);
        if (seen.has(id)) return;

        const stick = own || nearBottom();
        seen.add(id);
        list.appendChild(renderMessage(message));
        if (stick) scrollToBottom();
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
