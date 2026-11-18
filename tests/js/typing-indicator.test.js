// ISSUE-17: typing indicators. Run with `npm test` (node --test, no deps).
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

import {
    TYPING_EXPIRE_MS,
    TYPING_THROTTLE_MS,
    TypingTracker,
    createTypingThrottle,
    formatTyping,
    initTypingIndicator,
    typingParts,
} from '../../resources/js/typing-indicator.js';

// --- Fakes -------------------------------------------------------------

function fakeTimers() {
    let now = 0;
    let nextId = 1;
    const timers = new Map();

    return {
        now: () => now,
        setTimer: (callback, ms) => {
            const id = nextId++;
            timers.set(id, { at: now + ms, callback });
            return id;
        },
        clearTimer: (id) => timers.delete(id),
        advance(ms) {
            now += ms;
            [...timers.entries()]
                .filter(([, timer]) => timer.at <= now)
                .forEach(([id, timer]) => {
                    timers.delete(id);
                    timer.callback();
                });
        },
        pending: () => timers.size,
    };
}

/** A presence channel as returned by Echo.join(). */
function fakeEcho() {
    const handlers = {};
    const whispers = [];
    const channel = {
        here(cb) { handlers.here = cb; return channel; },
        joining(cb) { handlers.joining = cb; return channel; },
        leaving(cb) { handlers.leaving = cb; return channel; },
        listenForWhisper(event, cb) { handlers[`whisper:${event}`] = cb; return channel; },
        whisper(event, data) { whispers.push([event, data]); return channel; },
    };
    const echo = {
        joined: null,
        join(name) { echo.joined = name; return channel; },
    };

    return { echo, handlers, whispers };
}

function fakeOutput() {
    return {
        nodes: [],
        replaceChildren(...nodes) { this.nodes = nodes; },
        get text() { return this.nodes.map((node) => node.textContent).join(''); },
        get bold() { return this.nodes.filter((node) => node.tag === 'strong').map((node) => node.textContent); },
    };
}

function fakeInput() {
    const input = new EventTarget();
    input.value = '';
    input.type = (value) => {
        input.value = value;
        input.dispatchEvent(new Event('input'));
    };
    return input;
}

beforeEach(() => {
    globalThis.document = {
        createTextNode: (text) => ({ tag: '#text', textContent: text }),
        createElement: (tag) => ({ tag, textContent: '', className: '' }),
    };
});

// --- Text --------------------------------------------------------------

test('formats one, two, three and many typists', () => {
    assert.equal(formatTyping([]), '');
    assert.equal(formatTyping(['Ann']), 'Ann is typing…');
    assert.equal(formatTyping(['Ann', 'Bob']), 'Ann and Bob are typing…');
    assert.equal(formatTyping(['Ann', 'Bob', 'Cy']), 'Ann, Bob and Cy are typing…');
    assert.equal(formatTyping(['Ann', 'Bob', 'Cy', 'Di']), 'Several people are typing…');
});

// --- Throttle ----------------------------------------------------------

test('throttle sends on the first keystroke, then at most once per interval', () => {
    const clock = fakeTimers();
    let sent = 0;
    const throttle = createTypingThrottle(() => sent++, { now: clock.now });

    assert.equal(throttle.typed(), true);
    for (let i = 0; i < 20; i++) {
        clock.advance(100);
        throttle.typed();
    }
    assert.equal(sent, 1, 'no extra sends within the interval');

    clock.advance(TYPING_THROTTLE_MS - 2000);
    throttle.typed();
    assert.equal(sent, 2, 'sends again once the interval has passed');
});

test('throttle reset lets the next keystroke send immediately', () => {
    const clock = fakeTimers();
    let sent = 0;
    const throttle = createTypingThrottle(() => sent++, { now: clock.now });

    throttle.typed();
    throttle.reset();
    throttle.typed();
    assert.equal(sent, 2);
});

test('the throttle interval is shorter than the expiry, so an active typist never flickers', () => {
    assert.ok(TYPING_THROTTLE_MS < TYPING_EXPIRE_MS);
});

// --- Tracker -----------------------------------------------------------

test('tracker expires a typist after the expiry without a new whisper', () => {
    const clock = fakeTimers();
    const changes = [];
    const tracker = new TypingTracker({ ...clock, onChange: (names) => changes.push(names) });

    tracker.typing(1, 'Ann');
    assert.deepEqual(tracker.names(), ['Ann']);

    clock.advance(TYPING_EXPIRE_MS - 1);
    assert.deepEqual(tracker.names(), ['Ann']);
    clock.advance(1);
    assert.deepEqual(tracker.names(), []);
    assert.deepEqual(changes, [['Ann'], []]);
    assert.equal(clock.pending(), 0);
});

test('tracker refreshes the expiry on every whisper without re-rendering', () => {
    const clock = fakeTimers();
    let renders = 0;
    const tracker = new TypingTracker({ ...clock, onChange: () => renders++ });

    tracker.typing(1, 'Ann');
    clock.advance(TYPING_EXPIRE_MS - 1000);
    tracker.typing('1', 'Ann');
    clock.advance(TYPING_EXPIRE_MS - 1000);

    assert.deepEqual(tracker.names(), ['Ann']);
    assert.equal(renders, 1);
    assert.equal(clock.pending(), 1, 'the old timer was cleared');
});

test('tracker keeps typists in the order they started and stops them individually', () => {
    const clock = fakeTimers();
    const tracker = new TypingTracker(clock);

    tracker.typing(2, 'Bob');
    tracker.typing(1, 'Ann');
    tracker.typing(2, 'Bob');
    assert.deepEqual(tracker.names(), ['Bob', 'Ann']);

    tracker.stopped(2);
    tracker.stopped(99);
    assert.deepEqual(tracker.names(), ['Ann']);

    tracker.clear();
    assert.deepEqual(tracker.names(), []);
    assert.equal(clock.pending(), 0);
});

// --- Wiring ------------------------------------------------------------

function setup({ viewerId = 1 } = {}) {
    const { echo, handlers, whispers } = fakeEcho();
    const input = fakeInput();
    const output = fakeOutput();
    const typing = initTypingIndicator({ echo, channelId: '7', viewerId, input, output });
    handlers.here([{ id: 1, name: 'Me' }, { id: 2, name: 'Ann' }, { id: 3, name: 'Bob' }]);
    const whisperFrom = (userId, data = { typing: true }) => handlers['whisper:typing'](data, { user_id: userId });

    return { echo, handlers, whispers, input, output, typing, whisperFrom };
}

test('joins the typing presence channel of the channel', () => {
    const { echo } = setup();
    assert.equal(echo.joined, 'typing.7');
});

test('a DM joins the presence channel it is given (ISSUE-23)', () => {
    const { echo } = fakeEcho();
    initTypingIndicator({ echo, channelId: undefined, channelName: 'conversation-typing.12', viewerId: 1, input: null, output: fakeOutput() });
    assert.equal(echo.joined, 'conversation-typing.12');
});

test('does nothing without Echo', () => {
    assert.equal(initTypingIndicator({ echo: undefined, channelId: '7', viewerId: 1, input: null, output: fakeOutput() }), null);
});

test('shows the presence name of whoever whispers, in bold', () => {
    const { output, whisperFrom } = setup();

    whisperFrom(2);
    assert.equal(output.text, 'Ann is typing…');
    assert.deepEqual(output.bold, ['Ann']);

    whisperFrom(3);
    assert.equal(output.text, 'Ann and Bob are typing…');

    whisperFrom(2, { typing: false });
    assert.equal(output.text, 'Bob is typing…');
});

test('the sender comes from the Reverb user_id, never from the payload', () => {
    const { handlers, output, whisperFrom } = setup();

    // No user_id metadata (e.g. client events from non-members): ignored.
    handlers['whisper:typing']({ typing: true, user_id: 2, name: 'Spoofed' }, {});
    // Not (or no longer) a presence member: ignored.
    whisperFrom(42);
    // Your own other tab: ignored.
    whisperFrom(1);

    assert.equal(output.text, '');
});

test('leaving the channel or posting a message clears that typist', () => {
    const { handlers, output, typing, whisperFrom } = setup();

    whisperFrom(2);
    whisperFrom(3);
    handlers.leaving({ id: 2, name: 'Ann' });
    assert.equal(output.text, 'Bob is typing…');

    typing.messageArrived(3);
    assert.equal(output.text, '');

    // Ann left: her late whisper does not bring her back.
    whisperFrom(2);
    assert.equal(output.text, '');
});

test('members who join later can be shown as typing', () => {
    const { handlers, output, whisperFrom } = setup();

    handlers.joining({ id: 4, name: 'Di' });
    whisperFrom(4);
    assert.equal(output.text, 'Di is typing…');
});

test('typing whispers are throttled and sending or clearing whispers a stop', () => {
    const { input, whispers, typing } = setup();

    input.type('h');
    input.type('he');
    input.type('hey');
    assert.deepEqual(whispers, [['typing', { typing: true }]]);

    typing.sent();
    assert.deepEqual(whispers.at(-1), ['typing', { typing: false }]);

    input.type('n');
    assert.deepEqual(whispers.at(-1), ['typing', { typing: true }], 'after sending, the next keystroke announces again');

    input.type('');
    assert.deepEqual(whispers.at(-1), ['typing', { typing: false }]);
    assert.equal(whispers.length, 4);

    // Whitespace only, or sending with nothing announced, whispers nothing.
    input.type('   ');
    typing.sent();
    assert.equal(whispers.length, 4);
});

test('a whisper that throws (not subscribed yet) does not break typing', () => {
    const { echo, handlers } = fakeEcho();
    const channel = echo.join('x');
    channel.whisper = () => { throw new Error('not subscribed'); };
    const input = fakeInput();
    initTypingIndicator({ echo, channelId: '7', viewerId: 1, input, output: fakeOutput() });
    handlers.here([]);

    assert.doesNotThrow(() => input.type('hi'));
});

// --- Edge cases ----------------------------------------------------------

test('typingParts marks only names as bold segments', () => {
    assert.deepEqual(typingParts(['Ann', 'Bob']), [
        { text: 'Ann', name: true },
        { text: ' and ', name: false },
        { text: 'Bob', name: true },
        { text: ' are typing…', name: false },
    ]);
    assert.deepEqual(typingParts([]), []);
    assert.deepEqual(typingParts(['A', 'B', 'C', 'D']), [{ text: 'Several people are typing…', name: false }]);
});

test('throttle typed() reports whether it sent, and sends again exactly one interval later', () => {
    const clock = fakeTimers();
    const throttle = createTypingThrottle(() => {}, { interval: 1000, now: clock.now });

    assert.equal(throttle.typed(), true);
    clock.advance(999);
    assert.equal(throttle.typed(), false);
    clock.advance(1);
    assert.equal(throttle.typed(), true);
});

test('tracker re-renders when a typist\'s name changes', () => {
    const clock = fakeTimers();
    const changes = [];
    const tracker = new TypingTracker({ ...clock, onChange: (names) => changes.push(names) });

    tracker.typing(1, 'Ann');
    tracker.typing(1, 'Annie');
    assert.deepEqual(changes, [['Ann'], ['Annie']]);
    assert.equal(clock.pending(), 1);
});

test('tracker stopped() for an unknown id and clear() when empty do not re-render', () => {
    const clock = fakeTimers();
    let renders = 0;
    const tracker = new TypingTracker({ ...clock, onChange: () => renders++ });

    tracker.stopped(1);
    tracker.clear();
    assert.equal(renders, 0);
});

test('tracker treats numeric and string ids as the same typist', () => {
    const clock = fakeTimers();
    const tracker = new TypingTracker(clock);

    tracker.typing(5, 'Ann');
    tracker.typing('5', 'Ann');
    assert.deepEqual(tracker.names(), ['Ann']);
    tracker.stopped(5);
    assert.deepEqual(tracker.names(), []);
});

test('does nothing without an output element', () => {
    const { echo } = fakeEcho();
    assert.equal(initTypingIndicator({ echo, channelId: '7', viewerId: 1, input: null, output: null }), null);
    assert.equal(echo.joined, null, 'does not join the presence channel');
});

test('viewers without a composer still see who is typing', () => {
    const { echo, handlers, whispers } = fakeEcho();
    const output = fakeOutput();
    const typing = initTypingIndicator({ echo, channelId: '7', viewerId: 1, input: null, output });
    handlers.here([{ id: 2, name: 'Ann' }]);

    handlers['whisper:typing']({ typing: true }, { user_id: 2 });
    assert.equal(output.text, 'Ann is typing…');

    typing.sent();
    assert.equal(whispers.length, 0, 'nothing was announced, so nothing to stop');
});

test('names are inserted as text, never as markup', () => {
    const { handlers, output, whisperFrom } = setup();
    handlers.joining({ id: 9, name: '<img src=x onerror=alert(1)>' });

    whisperFrom(9);
    const strong = output.nodes.find((node) => node.tag === 'strong');
    assert.equal(strong.textContent, '<img src=x onerror=alert(1)>');
    assert.equal(strong.innerHTML, undefined, 'innerHTML is never set');
});

test('a whisper without a payload, or without typing:false, counts as typing', () => {
    const { handlers, output } = setup();

    handlers['whisper:typing'](undefined, { user_id: 2 });
    assert.equal(output.text, 'Ann is typing…');

    handlers['whisper:typing']({ typing: 'no' }, { user_id: 3 });
    assert.equal(output.text, 'Ann and Bob are typing…');
});

test('a whisper with no metadata at all is ignored', () => {
    const { handlers, output } = setup();

    assert.doesNotThrow(() => handlers['whisper:typing']({ typing: true }, undefined));
    assert.equal(output.text, '');
});

test('a numeric or string user_id both match the presence member', () => {
    const { output, whisperFrom } = setup();

    whisperFrom('2');
    whisperFrom(2);
    assert.equal(output.text, 'Ann is typing…');
    whisperFrom('2', { typing: false });
    assert.equal(output.text, '');
});

test('a presence member without a name shows an empty name rather than "undefined"', () => {
    const { handlers, output, whisperFrom } = setup();
    handlers.joining({ id: 8 });

    whisperFrom(8);
    assert.equal(output.text, ' is typing…');
});

test('a new here() list replaces the old members (reconnect)', () => {
    const { handlers, output, whisperFrom } = setup();

    handlers.here([{ id: 1, name: 'Me' }, { id: 3, name: 'Bob' }]);
    whisperFrom(2);
    assert.equal(output.text, '', 'Ann is no longer in the channel');
    whisperFrom(3);
    assert.equal(output.text, 'Bob is typing…');
});

test('messageArrived without an author, or from someone not typing, changes nothing', () => {
    const { output, typing, whisperFrom } = setup();

    whisperFrom(2);
    typing.messageArrived(null);
    typing.messageArrived(undefined);
    typing.messageArrived(3);
    assert.equal(output.text, 'Ann is typing…');
    typing.messageArrived('2');
    assert.equal(output.text, '');
});

test('only the viewer\'s own id is ignored', () => {
    const { output, whisperFrom } = setup({ viewerId: 3 });

    whisperFrom(1);
    assert.equal(output.text, 'Me is typing…', 'user 1 is someone else for viewer 3');
    whisperFrom(3);
    assert.equal(output.text, 'Me is typing…', 'own whispers are still ignored');
});

test('wired up with real timers: typists expire and long typing re-announces', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    const { input, output, whispers, whisperFrom } = setup();

    whisperFrom(2);
    t.mock.timers.tick(TYPING_EXPIRE_MS - 1);
    assert.equal(output.text, 'Ann is typing…');
    t.mock.timers.tick(1);
    assert.equal(output.text, '', 'expired without a new whisper');

    input.type('a');
    t.mock.timers.tick(TYPING_THROTTLE_MS - 1);
    input.type('ab');
    assert.equal(whispers.length, 1);
    t.mock.timers.tick(1);
    input.type('abc');
    assert.equal(whispers.length, 2, 'still typing after the interval whispers again');
    assert.deepEqual(whispers.at(-1), ['typing', { typing: true }]);
});
