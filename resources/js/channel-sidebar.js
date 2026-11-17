/**
 * Channel sidebar (resources/views/servers/partials/sidebar.blade.php).
 *
 * - Categories collapse and expand. The collapsed ones are remembered per
 *   server in localStorage. Like Discord, a collapsed category still shows
 *   the open channel.
 * - When the sidebar has [data-layout-url] (the user may manage channels),
 *   channels and categories can be reordered with drag and drop, or with
 *   Alt+ArrowUp / Alt+ArrowDown on a focused channel or category. Every
 *   change PUTs the whole layout; see App\Actions\Channels\ReorderChannels.
 * - Forms with [data-confirm] ask before submitting (delete channel/category).
 */

const STORAGE_PREFIX = 'fakediscord:collapsed-categories:';

function readCollapsed(serverId) {
    try {
        const ids = JSON.parse(window.localStorage.getItem(STORAGE_PREFIX + serverId) ?? '[]');
        return new Set(Array.isArray(ids) ? ids.map(String) : []);
    } catch {
        return new Set();
    }
}

function writeCollapsed(serverId, collapsed) {
    try {
        window.localStorage.setItem(STORAGE_PREFIX + serverId, JSON.stringify([...collapsed]));
    } catch {
        // Storage can be full or blocked; collapsing still works for this page view.
    }
}

function applyCollapsed(section, collapsed) {
    section.querySelector('[data-category-toggle]')?.setAttribute('aria-expanded', String(!collapsed));
    section.querySelector('[data-category-chevron]')?.classList.toggle('-rotate-90', collapsed);
    section.querySelectorAll('[data-channel]').forEach((item) => {
        item.hidden = collapsed && !item.hasAttribute('data-active');
    });
}

function channelIds(list) {
    return [...list.querySelectorAll(':scope > [data-channel]')].map((item) => Number(item.dataset.channelId));
}

export function currentLayout(sidebar) {
    return {
        uncategorized: channelIds(sidebar.querySelector('[data-channel-list][data-category-id=""]')),
        categories: [...sidebar.querySelectorAll('[data-category]')].map((section) => ({
            id: Number(section.dataset.categoryId),
            channels: channelIds(section.querySelector('[data-channel-list]')),
        })),
    };
}

/** The first visible item below the pointer, to insert the dragged item before. */
function itemAfter(container, selector, y, dragged) {
    return [...container.querySelectorAll(selector)]
        .filter((item) => item !== dragged && !item.hidden)
        .find((item) => {
            const box = item.getBoundingClientRect();
            return y < box.top + box.height / 2;
        }) ?? null;
}

function moveTo(container, item, before) {
    if (item.parentNode === container && item.nextElementSibling === before) return;
    container.insertBefore(item, before);
}

function initSidebar(sidebar) {
    const serverId = sidebar.dataset.serverId;
    const collapsed = readCollapsed(serverId);

    const sections = () => [...sidebar.querySelectorAll('[data-category]')];
    const refreshCollapsed = () => sections().forEach((section) => {
        applyCollapsed(section, collapsed.has(section.dataset.categoryId));
    });

    refreshCollapsed();

    sidebar.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-category-toggle]');
        if (!toggle) return;

        const id = toggle.closest('[data-category]').dataset.categoryId;
        collapsed.has(id) ? collapsed.delete(id) : collapsed.add(id);
        writeCollapsed(serverId, collapsed);
        refreshCollapsed();
    });

    const url = sidebar.dataset.layoutUrl;
    if (!url) return;

    sidebar.querySelector('[data-reorder-hint]')?.classList.remove('hidden');
    const errorBox = sidebar.querySelector('[data-reorder-error]');

    // Saves run one after another so quick successive moves arrive in order.
    let queue = Promise.resolve();
    let saved = JSON.stringify(currentLayout(sidebar));

    const save = () => {
        const layout = currentLayout(sidebar);
        const body = JSON.stringify(layout);
        if (body === saved) return;
        saved = body;

        queue = queue
            .then(() => window.axios.put(url, layout))
            .then(() => errorBox?.classList.add('hidden'))
            .catch((error) => {
                const data = error.response?.data;
                const message = data?.errors?.layout?.[0]
                    ?? data?.message
                    ?? 'The new order could not be saved. Reload the page and try again.';
                if (errorBox) {
                    errorBox.textContent = message;
                    errorBox.classList.remove('hidden');
                }
            });
    };

    const expand = (section) => {
        if (section && collapsed.delete(section.dataset.categoryId)) {
            writeCollapsed(serverId, collapsed);
            refreshCollapsed();
        }
    };

    // --- Drag and drop -------------------------------------------------

    let drag = null; // { item, kind: 'channel' | 'category' }

    sidebar.addEventListener('dragstart', (event) => {
        const channel = event.target.closest('[data-channel][draggable="true"]');
        const category = event.target.closest('[data-category][draggable="true"]');
        const item = channel ?? category;
        if (!item) return;

        drag = { item, kind: channel ? 'channel' : 'category' };
        event.dataTransfer.effectAllowed = 'move';
        // Firefox only starts a drag when some data is set.
        event.dataTransfer.setData('text/plain', item.dataset.channelId ?? item.dataset.categoryId);
        requestAnimationFrame(() => item.classList.add('opacity-50'));
    });

    sidebar.addEventListener('dragover', (event) => {
        if (!drag) return;

        if (drag.kind === 'channel') {
            // Over a channel list, or over a category header (drops at its end).
            const list = event.target.closest('[data-channel-list]')
                ?? event.target.closest('[data-category]')?.querySelector('[data-channel-list]');
            if (!list) return;

            event.preventDefault();
            moveTo(list, drag.item, itemAfter(list, ':scope > [data-channel]', event.clientY, drag.item));
        } else {
            const container = sidebar.querySelector('[data-category-list]');
            if (!container.contains(event.target)) return;

            event.preventDefault();
            moveTo(container, drag.item, itemAfter(container, ':scope > [data-category]', event.clientY, drag.item));
        }
    });

    sidebar.addEventListener('drop', (event) => {
        if (drag) event.preventDefault();
    });

    sidebar.addEventListener('dragend', () => {
        if (!drag) return;
        drag.item.classList.remove('opacity-50');
        drag = null;
        refreshCollapsed();
        save();
    });

    // --- Keyboard: Alt+ArrowUp / Alt+ArrowDown -------------------------

    sidebar.addEventListener('keydown', (event) => {
        if (!event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) return;

        const up = event.key === 'ArrowUp';
        const focused = event.target;
        const channel = focused.closest('[data-channel]');
        const category = !channel && focused.closest('[data-category-toggle]') ? focused.closest('[data-category]') : null;

        if (channel) {
            const list = channel.parentElement;
            const siblings = [...list.querySelectorAll(':scope > [data-channel]')];
            const index = siblings.indexOf(channel);
            const lists = [...sidebar.querySelectorAll('[data-channel-list]')];
            const listIndex = lists.indexOf(list);

            if (up && index > 0) {
                list.insertBefore(channel, siblings[index - 1]);
            } else if (!up && index < siblings.length - 1) {
                list.insertBefore(channel, siblings[index + 1].nextElementSibling);
            } else if (up && listIndex > 0) {
                lists[listIndex - 1].appendChild(channel); // to the end of the previous group
                expand(lists[listIndex - 1].closest('[data-category]'));
            } else if (!up && listIndex < lists.length - 1) {
                lists[listIndex + 1].prepend(channel); // to the top of the next group
                expand(lists[listIndex + 1].closest('[data-category]'));
            } else {
                return;
            }
        } else if (category) {
            const sibling = up ? category.previousElementSibling : category.nextElementSibling;
            if (!sibling?.matches('[data-category]')) return;
            category.parentElement.insertBefore(category, up ? sibling : sibling.nextElementSibling);
        } else {
            return;
        }

        event.preventDefault();
        focused.focus(); // moving a node can drop focus
        refreshCollapsed();
        save();
    });
}

document.querySelectorAll('[data-channel-sidebar]').forEach(initSidebar);

document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});
