/**
 * Online presence (ISSUE-31).
 *
 * Every page of a signed-in user joins the presence channel "server.{id}"
 * of each server they are in (<meta name="presence-servers">, authorised
 * in routes/channels.php), so they show as online everywhere, not only on
 * the server they are looking at. On a page with a member list
 * ([data-member-list][data-server-id]) that server's channel also drives
 * the list:
 *
 * - here / joining: those members are online, shown with the status they
 *   picked. Reverb counts a user once however many tabs they have open:
 *   "joining" comes with their first tab, "leaving" after their last.
 * - leaving: they stay as they are for PRESENCE_GRACE_MS and only then go
 *   offline. Moving to another page closes the socket for a moment; the
 *   grace period keeps that from blinking them offline and back.
 * - ".user.status" (App\Events\UserStatusChanged): they picked online,
 *   idle or do not disturb.
 *
 * Presence member info is fixed when a tab subscribes (and Reverb keeps
 * the first tab's), so for members already on the page the status in the
 * page (data-user-status, read from the database) wins over here();
 * joining brings fresh info.
 *
 * The viewer always counts as online. Their own status picker
 * ([data-status-picker], <x-status-picker>) saves with PUT
 * <meta name="status-url">; their other tabs hear about it on
 * "App.Models.User.{id}".
 *
 * Moving a member between groups follows App\Support\MemberList: online
 * members under their highest role (data-role-group), or "online", and
 * everyone offline under "offline"; each group sorted by data-sort-name.
 * Names are only ever moved, never parsed as HTML.
 *
 * PresenceTracker takes its timers as options and initPresence() its
 * echo, document and fetch, so tests/js/presence.test.js can drive them.
 */

export const PRESENCE_GRACE_MS = 10000;
export const STATUS_EVENT = '.user.status';
export const STATUSES = ['online', 'idle', 'dnd'];
export const OFFLINE = 'offline';

export const STATUS_COLORS = { online: '#23a55a', idle: '#f0b232', dnd: '#f23f43', offline: '#80848e' };
export const STATUS_LABELS = { online: 'Online', idle: 'Idle', dnd: 'Do Not Disturb', offline: 'Offline' };

/** A status someone picked; anything unknown reads as "online", like User::chosenStatus(). */
export function chosenStatus(status) {
    return STATUSES.includes(status) ? status : 'online';
}

/** "1,2,3" from <meta name="presence-servers"> as [1, 2, 3]. */
export function parseServerIds(content) {
    return [...new Set(String(content ?? '')
        .split(',')
        .map((part) => Number(part.trim()))
        .filter((id) => Number.isInteger(id) && id > 0))];
}

export function presenceChannel(serverId) {
    return `server.${serverId}`;
}

/**
 * Who is connected and what they show. onChange(id, status) runs whenever
 * the status shown for someone changes ("online", "idle", "dnd" or
 * "offline"). Ids are compared as strings.
 */
export class PresenceTracker {
    constructor({
        selfId = null,
        graceMs = PRESENCE_GRACE_MS,
        onChange = () => {},
        setTimer = (callback, ms) => setTimeout(callback, ms),
        clearTimer = (id) => clearTimeout(id),
    } = {}) {
        this.selfId = selfId == null ? null : String(selfId);
        this.graceMs = graceMs;
        this.onChange = onChange;
        this.setTimer = setTimer;
        this.clearTimer = clearTimer;
        /** @type {Map<string, string>} id => status they picked */
        this.chosen = new Map();
        /** @type {Set<string>} */
        this.connected = new Set();
        /** @type {Map<string, unknown>} id => grace timer */
        this.leavingTimers = new Map();
        /** @type {Map<string, string>} id => status last shown */
        this.lastShown = new Map();
    }

    /** What the page already shows: the status they picked and whether they are shown online. */
    seed(id, chosen, online) {
        const key = String(id);
        this.chosen.set(key, chosenStatus(chosen));
        if (online) this.connected.add(key);
        this.lastShown.set(key, online ? chosenStatus(chosen) : OFFLINE);
        // Only differs for the viewer, who always counts as online.
        this.refresh(key);
    }

    /** The full member list, on subscribing (again, after a reconnect). */
    here(members) {
        const present = new Set();

        for (const member of members ?? []) {
            if (member?.id == null) continue;
            const key = String(member.id);
            present.add(key);
            this.cancelGrace(key);
            this.connected.add(key);
            if (!this.chosen.has(key)) this.chosen.set(key, chosenStatus(member.status));
            this.refresh(key);
        }

        for (const key of [...this.connected]) {
            if (!present.has(key)) this.disconnected(key);
        }
    }

    joining(member) {
        if (member?.id == null) return;
        const key = String(member.id);
        this.cancelGrace(key);
        this.connected.add(key);
        // Fresh from routes/channels.php: this tab just subscribed.
        if (member.status !== undefined) this.chosen.set(key, chosenStatus(member.status));
        this.refresh(key);
    }

    leaving(member) {
        if (member?.id == null) return;
        this.disconnected(String(member.id));
    }

    statusChanged(id, status) {
        if (id == null || !STATUSES.includes(status)) return;
        const key = String(id);
        this.chosen.set(key, status);
        this.refresh(key);
    }

    /** "online", "idle", "dnd" or "offline". */
    shown(id) {
        const key = String(id);
        const here = key === this.selfId || this.connected.has(key) || this.leavingTimers.has(key);

        return here ? chosenStatus(this.chosen.get(key)) : OFFLINE;
    }

    /** Cancel every grace timer (the page is going away). */
    stop() {
        this.leavingTimers.forEach((timer) => this.clearTimer(timer));
        this.leavingTimers.clear();
    }

    disconnected(key) {
        if (key === this.selfId) return;
        const wasConnected = this.connected.delete(key);
        if (!wasConnected || this.leavingTimers.has(key)) return;

        this.leavingTimers.set(key, this.setTimer(() => {
            this.leavingTimers.delete(key);
            this.refresh(key);
        }, this.graceMs));
    }

    cancelGrace(key) {
        const timer = this.leavingTimers.get(key);
        if (timer === undefined) return;
        this.clearTimer(timer);
        this.leavingTimers.delete(key);
    }

    refresh(key) {
        const status = this.shown(key);
        if (this.lastShown.get(key) === status) return;
        this.lastShown.set(key, status);
        this.onChange(key, status);
    }
}

/** Which group a member belongs in: their role group when online, "offline" otherwise. */
export function groupKeyFor(roleGroup, status) {
    if (status === OFFLINE) return 'offline';

    return roleGroup || 'online';
}

/**
 * The member sidebar (servers/partials/member-list.blade.php).
 * apply(id, status) restyles a member and moves them to the right group.
 */
export function createMemberListView(root) {
    const item = (id) => root.querySelector(`[data-user-id="${String(id)}"]`);
    const section = (key) => root.querySelector(`[data-member-group="${key}"]`);

    const updateCounts = () => {
        let online = 0;
        let total = 0;

        for (const group of root.querySelectorAll('[data-member-group]')) {
            const list = group.querySelector('[data-member-items]');
            const count = list ? list.children.length : 0;
            const heading = group.querySelector('[data-member-heading]');
            if (heading) heading.textContent = `${heading.dataset.label ?? ''} — ${count}`;
            group.hidden = count === 0;
            total += count;
            if (group.dataset.memberGroup !== 'offline') online += count;
        }

        const summary = root.querySelector('[data-member-summary]');
        if (summary) summary.textContent = `Members (${online} online of ${total})`;
    };

    const insertSorted = (list, li) => {
        const name = li.dataset.sortName ?? '';
        const before = [...list.children].find((other) => other !== li && (other.dataset.sortName ?? '') > name);
        if (before) {
            list.insertBefore(li, before);
        } else {
            list.appendChild(li);
        }
    };

    return {
        /** [{id, chosen, online}] for PresenceTracker.seed(). */
        entries() {
            return [...root.querySelectorAll('[data-user-id]')].map((li) => ({
                id: li.dataset.userId,
                chosen: li.dataset.userStatus,
                online: (li.dataset.presence ?? OFFLINE) !== OFFLINE,
            }));
        },

        apply(id, status) {
            const li = item(id);
            if (!li) return false;

            const online = status !== OFFLINE;
            li.dataset.presence = status;
            li.classList.toggle('opacity-40', !online);
            li.classList.toggle('hover:opacity-100', !online);

            const dot = li.querySelector('[data-status-dot]');
            if (dot) dot.style.backgroundColor = STATUS_COLORS[status] ?? STATUS_COLORS.offline;
            const label = li.querySelector('[data-status-label]');
            if (label) label.textContent = `, ${STATUS_LABELS[status] ?? STATUS_LABELS.offline}`;

            // A role created after the page loaded has no section: "Online".
            const target = section(groupKeyFor(li.dataset.roleGroup, status)) ?? section(online ? 'online' : 'offline');
            const list = target?.querySelector('[data-member-items]');
            if (list) insertSorted(list, li);

            const name = li.querySelector('[data-member-name]');
            const color = online ? target?.dataset.roleColor : null;
            if (name) {
                name.style.color = color || '';
                name.classList.toggle('text-fg', !color);
            }

            updateCounts();
            return true;
        },
    };
}

/**
 * Wire a page up. Returns { tracker, setOwnStatus } or null without Echo
 * or a signed-in user.
 *
 * options: { echo, document, fetch, graceMs, setTimer, clearTimer }
 */
export function initPresence({ echo, document, fetch = null, graceMs = PRESENCE_GRACE_MS, setTimer, clearTimer }) {
    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') ?? null;
    const selfId = meta('user-id');
    if (!echo || !selfId) return null;

    const root = document.querySelector('[data-member-list]');
    const listServerId = Number(root?.dataset?.serverId);
    const view = root ? createMemberListView(root) : null;

    const timers = {};
    if (setTimer) timers.setTimer = setTimer;
    if (clearTimer) timers.clearTimer = clearTimer;

    const tracker = new PresenceTracker({
        selfId,
        graceMs,
        onChange: (id, status) => view?.apply(id, status),
        ...timers,
    });

    view?.entries().forEach(({ id, chosen, online }) => tracker.seed(id, chosen, online));
    tracker.statusChanged(selfId, chosenStatus(meta('user-status')));

    for (const serverId of parseServerIds(meta('presence-servers'))) {
        const channel = echo.join(presenceChannel(serverId));
        if (serverId !== listServerId) continue;

        channel
            .here((members) => tracker.here(members))
            .joining((member) => tracker.joining(member))
            .leaving((member) => tracker.leaving(member))
            .listen(STATUS_EVENT, (event) => tracker.statusChanged(event?.user_id, event?.status));
    }

    const picker = document.querySelector('[data-status-picker]');
    const select = picker?.querySelector('[data-status-select]');
    const dot = picker?.querySelector('[data-status-dot]');

    const setOwnStatus = (status) => {
        if (!STATUSES.includes(status)) return;
        document.querySelector('meta[name="user-status"]')?.setAttribute('content', status);
        if (select) select.value = status;
        if (dot) dot.style.backgroundColor = STATUS_COLORS[status];
        const li = root?.querySelector(`[data-user-id="${selfId}"]`);
        if (li) li.dataset.userStatus = status;
        tracker.statusChanged(selfId, status);
    };

    // Another tab of yours picked a status.
    echo.private(`App.Models.User.${selfId}`).listen(STATUS_EVENT, (event) => {
        if (String(event?.user_id) === String(selfId)) setOwnStatus(event.status);
    });

    if (picker && select && fetch) {
        picker.querySelector('[data-status-submit]')?.setAttribute('hidden', '');

        const save = async () => {
            const previous = chosenStatus(meta('user-status'));
            const status = select.value;
            setOwnStatus(status);

            try {
                const response = await fetch(meta('status-url') ?? picker.action, {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': meta('csrf-token') ?? '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ status }),
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                setOwnStatus(data?.status);
            } catch {
                setOwnStatus(previous);
            }
        };

        select.addEventListener('change', save);
        picker.addEventListener('submit', (event) => {
            event.preventDefault();
            save();
        });
    }

    return { tracker, setOwnStatus };
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    initPresence({
        echo: window.Echo,
        document,
        fetch: window.fetch.bind(window),
    });
}
