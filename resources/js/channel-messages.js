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
 * - Edit and delete (ISSUE-16): authors get "Edit" (inline, Enter saves,
 *   Escape cancels, PATCH data-message-url); authors and moderators
 *   (data-can-manage-messages) get "Delete" (asks first, DELETE
 *   data-message-url). Edits from others arrive as ".message.updated" and
 *   replace the content and show "(edited)"; ".message.deleted" removes
 *   the message. The buttons are only a hint, MessagePolicy decides.
 * - Typing indicators (ISSUE-17): see typing-indicator.js. The composer
 *   whispers while you type; [data-typing-indicator] shows who else is.
 *
 * All user-provided text is inserted with textContent, never parsed as HTML.
 * renderMessage() must match resources/views/messages/item.blade.php.
 */

import { initTypingIndicator } from './typing-indicator';

const NEAR_BOTTOM_PX = 120;
const LOAD_OLDER_PX = 400;
const MAX_CONTENT_LENGTH = 2000;

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

/**
 * Show or hide "(edited)" for a message's edited_at.
 */
export function applyEdited(marker, editedAt) {
    if (!marker) return;
    marker.hidden = !editedAt;
    if (editedAt) {
        marker.title = `Edited ${formatTime(editedAt) || editedAt}`;
    } else {
        marker.removeAttribute('title');
    }
}

/**
 * Edit / Delete, like the actions in messages/item.blade.php.
 * context: { viewerId, canManageMessages, messagesUrl, csrfToken }.
 */
function renderActions(message, url, context) {
    const own = message.author != null && String(message.author.id) === String(context.viewerId);
    if (!own && !context.canManageMessages) return null;

    const actions = el('div', 'absolute -top-3 right-4 hidden gap-1 rounded border border-[#1f2023] bg-[#313338] p-0.5 shadow group-hover:flex group-focus-within:flex');
    actions.dataset.messageActions = '';

    if (own) {
        const edit = el('button', 'rounded px-2 py-0.5 text-xs text-[#b5bac1] hover:bg-[#404249] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]', 'Edit');
        edit.type = 'button';
        edit.dataset.messageEdit = '';
        actions.appendChild(edit);
    }

    const form = el('form');
    form.method = 'POST';
    form.action = url;
    form.dataset.messageDeleteForm = '';
    [['_token', context.csrfToken ?? ''], ['_method', 'DELETE']].forEach(([name, value]) => {
        const input = el('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    });
    const remove = el('button', 'rounded px-2 py-0.5 text-xs text-red-400 hover:bg-[#404249] hover:text-red-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]', 'Delete');
    remove.type = 'submit';
    form.appendChild(remove);
    actions.appendChild(form);

    return actions;
}

export function renderMessage(message, context = {}) {
    const url = context.messagesUrl ? `${context.messagesUrl}/${message.id}` : '';

    const item = el('li', 'group relative flex gap-4 px-4 py-1 hover:bg-[#2e3035] focus-within:bg-[#2e3035]');
    item.dataset.message = '';
    item.dataset.messageId = String(message.id);
    item.dataset.messageUrl = url;

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

    const text = el('p', 'whitespace-pre-wrap break-words text-[#dbdee1]');
    text.dataset.messageBody = '';
    const content = el('span', null, message.content);
    content.dataset.messageContent = '';
    text.appendChild(content);
    const edited = el('span', 'ml-1 text-xs text-[#949ba4]', '(edited)');
    edited.dataset.messageEdited = '';
    applyEdited(edited, message.edited_at);
    text.appendChild(edited);
    body.appendChild(text);

    item.appendChild(body);

    const actions = url ? renderActions(message, url, context) : null;
    if (actions) item.appendChild(actions);

    return item;
}

function errorMessage(error) {
    const response = error.response;
    if (response?.status === 429) return 'You are sending messages too quickly. Wait a moment and try again.';
    if (response?.status === 403) return 'You cannot send messages in this channel.';
    return response?.data?.errors?.content?.[0] ?? 'Your message could not be sent. Try again.';
}

function editErrorMessage(error) {
    const response = error.response;
    if (response?.status === 429) return 'You are editing too quickly. Wait a moment and try again.';
    if (response?.status === 403) return 'You cannot edit this message.';
    if (response?.status === 404) return 'This message was deleted.';
    return response?.data?.errors?.content?.[0] ?? 'Your changes could not be saved. Try again.';
}

function socketHeaders() {
    const socketId = window.Echo?.socketId();
    return socketId ? { 'X-Socket-ID': socketId } : {};
}

function initChannel(root) {
    const channelId = root.dataset.channelId;
    const list = root.querySelector('[data-message-list]');
    const scroller = root.querySelector('[data-message-scroller]');
    const form = root.querySelector('[data-message-form]');
    if (!list || !scroller) return;

    const context = {
        viewerId: root.dataset.viewerId ?? '',
        canManageMessages: root.dataset.canManageMessages !== undefined,
        messagesUrl: root.dataset.historyUrl ?? '',
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
    };

    const seen = new Set([...list.querySelectorAll('[data-message]')].map((item) => item.dataset.messageId));

    // Server-rendered times are UTC; show them in the viewer's time zone.
    list.querySelectorAll('time[data-local-time]').forEach((time) => {
        time.textContent = formatTime(time.dateTime) || time.textContent;
    });
    list.querySelectorAll('[data-message-edited]:not([hidden])').forEach((marker) => {
        applyEdited(marker, marker.title.replace(/^Edited /, ''));
    });

    // Editing needs this script, so the server renders Edit hidden.
    list.querySelectorAll('[data-message-edit]').forEach((button) => {
        button.hidden = false;
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
            fragment.appendChild(renderMessage(message, context));
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

    const typing = initTypingIndicator({
        echo: window.Echo,
        channelId,
        viewerId: context.viewerId,
        input: form?.querySelector('[data-message-input]') ?? null,
        output: root.querySelector('[data-typing-indicator]'),
    });

    const append = (message, own = false) => {
        if (!message || String(message.channel_id) !== channelId) return;
        if (!own) typing?.messageArrived(message.author?.id);
        const id = String(message.id);
        if (seen.has(id)) return;

        const stick = own || nearBottom();
        seen.add(id);
        list.appendChild(renderMessage(message, context));
        if (stick) {
            scrollToBottom();
        } else {
            unseen += 1;
        }
        updateJump();
    };

    // --- Edit and delete (ISSUE-16) ---------------------------------------
    const findItem = (id) => list.querySelector(`[data-message][data-message-id="${CSS.escape(String(id))}"]`);

    // When you are at the bottom, stay there. Otherwise a message above the
    // viewport changing height must not move what you are reading.
    const changeItem = (item, change) => {
        if (nearBottom()) {
            change();
            scrollToBottom();
        } else if (item.offsetTop + item.offsetHeight <= scroller.scrollTop) {
            keepPosition(change);
        } else {
            change();
        }
        updateJump();
    };

    const applyUpdate = (message) => {
        if (!message || String(message.channel_id) !== channelId) return;
        const item = findItem(message.id);
        if (!item) return;
        changeItem(item, () => {
            const content = item.querySelector('[data-message-content]');
            if (content) content.textContent = message.content;
            applyEdited(item.querySelector('[data-message-edited]'), message.edited_at);
        });
    };

    const removeItem = (id) => {
        // Stays in `seen`, so a late ".message.sent" cannot bring it back.
        seen.add(String(id));
        const item = findItem(id);
        if (item) changeItem(item, () => item.remove());
    };

    const stopEditing = (item) => {
        item.querySelector('[data-message-editor]')?.remove();
        const body = item.querySelector('[data-message-body]');
        if (body) body.hidden = false;
        delete item.dataset.editing;
    };

    const startEditing = (item) => {
        if (item.dataset.editing !== undefined) return;
        const body = item.querySelector('[data-message-body]');
        const url = item.dataset.messageUrl;
        if (!body || !url) return;

        item.dataset.editing = '';

        const editor = el('div', 'mt-1');
        editor.dataset.messageEditor = '';
        const input = el('textarea', 'block max-h-60 w-full resize-none rounded-lg bg-[#383a40] px-4 py-2 text-[#dbdee1] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]');
        input.id = `message-edit-${item.dataset.messageId}`;
        input.rows = 1;
        input.maxLength = MAX_CONTENT_LENGTH;
        input.value = item.querySelector('[data-message-content]')?.textContent ?? '';
        input.setAttribute('aria-label', 'Edit message');
        input.setAttribute('aria-describedby', `${input.id}-hint ${input.id}-error`);
        const hint = el('p', 'mt-1 text-xs text-[#949ba4]', 'Escape to cancel · Enter to save');
        hint.id = `${input.id}-hint`;
        const error = el('p', 'mt-1 hidden text-sm text-red-400');
        error.id = `${input.id}-error`;
        error.setAttribute('role', 'alert');
        editor.append(input, hint, error);

        const showEditError = (text) => {
            error.textContent = text;
            error.classList.remove('hidden');
        };

        const resize = () => {
            input.style.height = 'auto';
            input.style.height = `${input.scrollHeight}px`;
        };

        const focusEditButton = () => item.querySelector('[data-message-edit]')?.focus({ preventScroll: true });

        let saving = false;
        const save = () => {
            if (saving) return;
            const content = input.value.trim();
            if (!content) {
                showEditError('A message cannot be empty. Delete it instead.');
                return;
            }

            saving = true;
            window.axios
                .patch(url, { content }, { headers: { Accept: 'application/json', ...socketHeaders() } })
                .then((response) => {
                    applyUpdate(response.data.message);
                    stopEditing(item);
                    focusEditButton();
                })
                .catch((failure) => showEditError(editErrorMessage(failure)))
                .finally(() => {
                    saving = false;
                });
        };

        input.addEventListener('input', resize);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                stopEditing(item);
                focusEditButton();
            } else if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                save();
            }
        });

        changeItem(item, () => {
            body.hidden = true;
            body.after(editor);
            resize();
        });
        input.focus({ preventScroll: true });
        input.setSelectionRange(input.value.length, input.value.length);
    };

    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-message-edit]');
        if (!button || !list.contains(button)) return;
        const item = button.closest('[data-message]');
        if (item) startEditing(item);
    });

    list.addEventListener('submit', (event) => {
        const deleteForm = event.target.closest('[data-message-delete-form]');
        if (!deleteForm) return;
        event.preventDefault();

        const item = deleteForm.closest('[data-message]');
        if (!item || item.dataset.deleting !== undefined) return;
        if (!window.confirm('Delete this message? This cannot be undone.')) return;

        item.dataset.deleting = '';
        window.axios
            .delete(item.dataset.messageUrl, { headers: { Accept: 'application/json', ...socketHeaders() } })
            .then(() => removeItem(item.dataset.messageId))
            .catch((failure) => {
                // Already gone (deleted elsewhere): just take it off the screen.
                if (failure.response?.status === 404) {
                    removeItem(item.dataset.messageId);
                    return;
                }
                window.alert(failure.response?.status === 403
                    ? 'You cannot delete this message.'
                    : 'The message could not be deleted. Try again.');
            })
            .finally(() => {
                delete item.dataset.deleting;
            });
    });

    window.Echo?.private(`channel.${channelId}`)
        .listen('.message.sent', (message) => append(message))
        .listen('.message.updated', (message) => applyUpdate(message))
        .listen('.message.deleted', (message) => {
            if (String(message?.channel_id) === channelId) removeItem(message.id);
        });

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
        typing?.sent();

        window.axios
            .post(form.action, { content }, { headers: socketHeaders() })
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
