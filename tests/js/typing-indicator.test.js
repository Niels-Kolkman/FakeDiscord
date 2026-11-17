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
