/**
 * Profile popover: clicking any element with [data-profile-url] (see the
 * <x-user-avatar> Blade component) fetches that user's profile JSON and shows
 * a small card next to it. Closes on outside click, Escape or a second click.
 * Opening focuses the card and Tab stays in it (focus-trap.js);
 * Escape gives focus back to the avatar or name that opened it.
 *
 * All user-provided text is inserted with textContent, never parsed as HTML.
 */
import { trapTab } from './focus-trap.js';

const cache = new Map();
let open = null; // { trigger, element }

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
}

function memberSince(iso) {
    if (!iso) return null;
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function render(profile) {
    const card = el('div', 'fixed z-50 w-72 overflow-hidden rounded-lg bg-surface-alt text-fg shadow-2xl focus:outline-none');
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-label', `Profile of ${profile.name}`);
    card.setAttribute('tabindex', '-1');

    card.appendChild(el('div', 'h-14 bg-[#5865f2]'));

    const body = el('div', 'px-4 pb-4');
    const avatar = el('img', '-mt-9 h-[72px] w-[72px] rounded-full border-[6px] border-surface-alt object-cover');
    avatar.src = profile.avatar_url;
    avatar.alt = '';
    body.appendChild(avatar);

    body.appendChild(el('p', 'mt-2 text-lg font-semibold text-fg-strong', profile.name));
    body.appendChild(el('p', 'text-sm text-fg-muted', `@${profile.username}`));

    if (profile.bio) {
        body.appendChild(el('p', 'mt-3 text-xs font-semibold uppercase tracking-wide text-fg-muted', 'About me'));
        body.appendChild(el('p', 'mt-1 whitespace-pre-line break-words text-sm', profile.bio));
    }

    const since = memberSince(profile.member_since);
    if (since) {
        body.appendChild(el('p', 'mt-3 text-xs font-semibold uppercase tracking-wide text-fg-muted', 'Member since'));
        body.appendChild(el('p', 'mt-1 text-sm', since));
    }

    const block = renderBlockButton(profile);
    if (block) body.appendChild(block);

    card.appendChild(body);
    return card;
}

/**
 * Block / Unblock, for anyone but yourself. Blocking posts
 * user_id to profile.block_url (FriendController::block), unblocking
 * DELETEs profile.unblock_url. Either reloads the page, so the blocked
 * person's messages disappear (or come back) everywhere at once.
 */
export function renderBlockButton(profile) {
    if (profile.is_self || !profile.block_url) return null;

    const button = el(
        'button',
        'mt-4 w-full rounded px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2] '
            + (profile.blocked ? 'bg-[#4e5058] text-white hover:bg-[#6d6f78]' : 'text-danger-fg hover:bg-[#da373c] hover:text-white'),
        profile.blocked ? 'Unblock' : 'Block',
    );
    button.type = 'button';
    button.dataset.profileBlock = profile.blocked ? 'unblock' : 'block';
    button.setAttribute('aria-label', `${profile.blocked ? 'Unblock' : 'Block'} ${profile.name}`);
    button.addEventListener('click', () => toggleBlock(profile, button));
    return button;
}

async function toggleBlock(profile, button) {
    if (!profile.blocked && !window.confirm(`Block ${profile.name}? You will not see their messages, and they cannot message you or send you friend requests.`)) {
        return;
    }

    button.disabled = true;
    try {
        const headers = { headers: { Accept: 'application/json' } };
        if (profile.blocked && profile.unblock_url) {
            await window.axios.delete(profile.unblock_url, headers);
        } else {
            await window.axios.post(profile.block_url, { user_id: profile.id }, headers);
        }
        cache.clear();
        window.location.reload();
    } catch {
        button.disabled = false;
        window.alert('That did not work. Please try again.');
    }
}

function position(card, trigger) {
    const rect = trigger.getBoundingClientRect();
    const margin = 8;
    const width = card.offsetWidth;
    const height = card.offsetHeight;

    // Prefer the right of the trigger, then the left, then clamp to the viewport.
    let left = rect.right + margin;
    if (left + width > window.innerWidth - margin) left = rect.left - width - margin;
    if (left < margin) left = Math.min(rect.left, window.innerWidth - width - margin);

    let top = rect.top;
    if (top + height > window.innerHeight - margin) top = window.innerHeight - height - margin;

    card.style.left = `${Math.max(margin, left)}px`;
    card.style.top = `${Math.max(margin, top)}px`;
}

function close() {
    if (!open) return;
    open.element.remove();
    open.trigger.setAttribute('aria-expanded', 'false');
    open = null;
}

async function load(url) {
    if (!cache.has(url)) {
        cache.set(url, window.axios.get(url, { headers: { Accept: 'application/json' } }).then((r) => r.data));
    }
    try {
        return await cache.get(url);
    } catch (error) {
        cache.delete(url);
        throw error;
    }
}

async function toggle(trigger) {
    const wasOpen = open?.trigger === trigger;
    close();
    if (wasOpen) return;

    let profile;
    try {
        profile = await load(trigger.dataset.profileUrl);
    } catch {
        return;
    }

    close(); // another popover may have opened while this one was loading
    const card = render(profile);
    document.body.appendChild(card);
    position(card, trigger);
    trigger.setAttribute('aria-expanded', 'true');
    open = { trigger, element: card };
    // The card is at the end of <body>: move focus to it.
    card.focus({ preventScroll: true });
}

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-profile-url]');
    if (trigger) {
        event.preventDefault();
        toggle(trigger);
        return;
    }
    if (open && !open.element.contains(event.target)) close();
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && open) {
        const { trigger } = open;
        close();
        trigger.focus();
        return;
    }
    // Tab stays in the card until Escape.
    if (open && open.element.contains(document.activeElement)) trapTab(open.element, event);
});

window.addEventListener('resize', close);
window.addEventListener('scroll', close, true);

// Live preview for the avatar file input on the profile settings page.
document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.dataset.avatarPreview) return;
    const preview = document.querySelector(input.dataset.avatarPreview);
    const file = input.files?.[0];
    if (preview && file && file.type.startsWith('image/')) {
        preview.src = URL.createObjectURL(file);
    }
});
