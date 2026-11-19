// A small fake DOM for the ISSUE-33 script tests (mention-autocomplete and
// notifications): elements with dataset, attributes, children, events and
// the selectors those scripts use ([data-foo], [data-foo][data-bar]).
// innerHTML throws, so a test fails loudly if user text were parsed as HTML.
// Not a test file itself: `npm test` only runs *.test.js.

const camel = (name) => name.replace(/-([a-z])/g, (_, c) => c.toUpperCase());

function matcher(selector) {
    const keys = [...selector.matchAll(/\[data-([a-z-]+)\]/g)].map((m) => camel(m[1]));
    if (keys.length === 0 || selector.replace(/\[data-[a-z-]+\]/g, '') !== '') {
        throw new Error(`Unsupported selector ${selector}`);
    }
    return (node) => node.dataset !== undefined && keys.every((key) => key in node.dataset);
}

export function fakeElement(tag = 'div') {
    const listeners = {};

    return {
        tagName: tag.toUpperCase(),
        id: '',
        className: '',
        textContent: '',
        hidden: false,
        style: {},
        dataset: {},
        attributes: {},
        children: [],
        parentNode: null,
        listeners,
        setAttribute(name, value) { this.attributes[name] = String(value); },
        getAttribute(name) { return this.attributes[name] ?? null; },
        removeAttribute(name) { delete this.attributes[name]; },
        appendChild(child) {
            child.parentNode = this;
            this.children.push(child);
            return child;
        },
        append(...children) { children.forEach((child) => this.appendChild(child)); },
        prepend(child) {
            child.parentNode = this;
            this.children.unshift(child);
        },
        replaceChildren(...children) {
            this.children.forEach((child) => { child.parentNode = null; });
            this.children = [];
            this.append(...children);
        },
        remove() {
            if (!this.parentNode) return;
            this.parentNode.children = this.parentNode.children.filter((child) => child !== this);
            this.parentNode = null;
        },
        contains(other) {
            for (let current = other; current; current = current.parentNode) {
                if (current === this) return true;
            }
            return false;
        },
        closest(selector) {
            const matches = matcher(selector);
            for (let current = this; current; current = current.parentNode) {
                if (matches(current)) return current;
            }
            return null;
        },
        querySelectorAll(selector) {
            const matches = matcher(selector);
            const found = [];
            const walk = (current) => (current.children ?? []).forEach((child) => {
                if (matches(child)) found.push(child);
                walk(child);
            });
            walk(this);
            return found;
        },
        querySelector(selector) {
            return this.querySelectorAll(selector)[0] ?? null;
        },
        addEventListener(type, handler) {
            (listeners[type] ??= []).push(handler);
        },
        dispatchEvent(event) {
            (listeners[event.type] ?? []).forEach((handler) => handler(event));
            return true;
        },
        get innerHTML() { throw new Error('innerHTML must not be used'); },
        set innerHTML(_) { throw new Error('innerHTML must not be used'); },
    };
}

/** An element with data attributes and children, for building pages. */
export function make(tag, dataset = {}, children = []) {
    const node = fakeElement(tag);
    Object.assign(node.dataset, dataset);
    children.forEach((child) => node.appendChild(child));
    return node;
}

/** A fake document whose querySelector searches `root`. */
export function fakeDocument(root = make('body')) {
    const listeners = {};

    return {
        body: root,
        visibilityState: 'visible',
        listeners,
        createElement: (tag) => fakeElement(tag),
        createTextNode: (text) => ({ textContent: String(text), parentNode: null }),
        querySelector: (selector) => root.querySelector(selector),
        querySelectorAll: (selector) => root.querySelectorAll(selector),
        addEventListener(type, handler) {
            (listeners[type] ??= []).push(handler);
        },
        dispatch(type, event) {
            (listeners[type] ?? []).forEach((handler) => handler(event));
        },
    };
}

/** A keydown-like event that records preventDefault / stopImmediatePropagation. */
export function keyEvent(key, extra = {}) {
    return {
        type: 'keydown',
        key,
        shiftKey: false,
        isComposing: false,
        defaultPrevented: false,
        stopped: false,
        preventDefault() { this.defaultPrevented = true; },
        stopImmediatePropagation() { this.stopped = true; },
        ...extra,
    };
}

/** Text of a node and everything in it. */
export function textOf(node) {
    if (!node) return '';
    if (!node.children || node.children.length === 0) return node.textContent ?? '';
    return node.children.map(textOf).join('');
}
