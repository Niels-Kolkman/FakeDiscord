// XSS review: the profile card (resources/js/profile-popover.js)
// shows a user's name, username and bio from /users/{id}/profile JSON. It
// must put them in as text, never as markup. Run with `npm test`. The fake
// DOM's innerHTML throws, so parsing user text as HTML would fail here too.
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { fakeDocument, make, textOf } from './helpers/fake-dom.js';

const PAYLOAD = '"><script>alert(1)</script><img src=x onerror=alert(2)>';

function withLayout(node) {
    node.focus = () => {};
    node.offsetWidth = 288;
    node.offsetHeight = 200;
    node.getBoundingClientRect = () => ({ top: 10, left: 10, right: 40, bottom: 40 });
    return node;
}

const doc = fakeDocument();
const createElement = doc.createElement;
doc.createElement = (tag) => withLayout(createElement(tag));
globalThis.document = doc;

let profile = null;
globalThis.window = {
    innerWidth: 1200,
    innerHeight: 800,
    addEventListener() {},
    axios: { get: async () => ({ data: profile }) },
    location: { reload() {} },
    confirm: () => false,
    alert() {},
};

await import('../../resources/js/profile-popover.js');

const settle = () => new Promise((resolve) => setImmediate(resolve));

function all(node) {
    return [node, ...(node.children ?? []).flatMap(all)];
}

async function openCard(data, url) {
    profile = data;
    const trigger = withLayout(make('button', { profileUrl: url }));
    doc.body.appendChild(trigger);
    doc.dispatch('click', { target: trigger, preventDefault() {} });
    await settle();
    await settle();
    const card = doc.body.children.find((node) => node.getAttribute('role') === 'dialog');
    assert.ok(card, 'the profile card opened');
    return { card, trigger };
}

test('puts the name, username and bio in as text', async () => {
    const { card, trigger } = await openCard({
        id: 2,
        name: `Name ${PAYLOAD}`,
        username: `user${PAYLOAD}`,
        bio: `</p>${PAYLOAD}`,
        avatar_url: '/avatars/2.png',
        member_since: '2026-01-02T00:00:00+00:00',
        is_self: false,
        blocked: false,
        block_url: '/friends/block',
        unblock_url: null,
    }, '/users/2/profile');

    const text = textOf(card);
    assert.ok(text.includes(`Name ${PAYLOAD}`));
    assert.ok(text.includes(`@user${PAYLOAD}`));
    assert.ok(text.includes(`</p>${PAYLOAD}`));

    // Only the elements the script makes itself: one img (the avatar), no script.
    const tags = all(card).map((node) => node.tagName);
    assert.ok(!tags.includes('SCRIPT'));
    assert.deepEqual(tags.filter((tag) => tag === 'IMG'), ['IMG']);
    assert.equal(all(card).find((node) => node.tagName === 'IMG').src, '/avatars/2.png');

    // Attributes carry the name as a plain string, never as markup.
    assert.equal(card.getAttribute('aria-label'), `Profile of Name ${PAYLOAD}`);
    const block = all(card).find((node) => node.dataset?.profileBlock);
    assert.equal(block.getAttribute('aria-label'), `Block Name ${PAYLOAD}`);
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
});

test('shows no bio section for an empty bio', async () => {
    const { card } = await openCard({
        id: 3,
        name: 'Plain',
        username: 'plain',
        bio: null,
        avatar_url: '/avatars/3.png',
        member_since: null,
        is_self: true,
        blocked: false,
        block_url: null,
        unblock_url: null,
    }, '/users/3/profile');

    assert.ok(!textOf(card).includes('About me'));
    assert.ok(!all(card).some((node) => node.dataset?.profileBlock));
});
