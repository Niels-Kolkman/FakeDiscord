// ISSUE-38: WCAG 2.2 AA contrast of the theme tokens in resources/css/app.css,
// in both themes. Text tokens need 4.5:1 on every background token (normal
// size text, 1.4.3), fg-faint 3:1 (decorative icons only, like non-text
// contrast in 1.4.11); fg-dim is only for disabled controls, which WCAG
// exempts. Run with `npm test`. The views' colour classes are checked by
// tests/Feature/Layout/ContrastTest.php.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

function tokens(block) {
    return Object.fromEntries([...block.matchAll(/--color-([a-z0-9-]+):\s*(#[0-9a-f]{6})\b/gi)].map((m) => [m[1], m[2].toLowerCase()]));
}

const dark = tokens(css.match(/@theme\s*\{([\s\S]*?)\n\}/)[1]);
const light = { ...dark, ...tokens(css.match(/:root\[data-theme='light'\]\s*\{([\s\S]*?)\}/)[1]) };

/** WCAG 2.x relative luminance of #rrggbb. */
function luminance(hex) {
    const [r, g, b] = [1, 3, 5]
        .map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
        .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a, b) {
    const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (high + 0.05) / (low + 0.05);
}

const BACKGROUNDS = ['surface', 'surface-2', 'surface-3', 'surface-alt', 'surface-float', 'hover-soft', 'hover', 'field', 'active'];
const TEXT = ['fg-strong', 'fg', 'fg-muted', 'fg-subtle', 'link', 'danger-fg', 'warn-fg'];

function failures(theme, names, minimum) {
    const failed = [];
    for (const name of names) {
        for (const bg of BACKGROUNDS) {
            const ratio = contrast(theme[name], theme[bg]);
            if (ratio < minimum) failed.push(`${name} on ${bg}: ${ratio.toFixed(2)}`);
        }
    }
    return failed;
}

test('contrast() matches the WCAG formula', () => {
    assert.equal(contrast('#000000', '#ffffff').toFixed(2), '21.00');
    assert.equal(contrast('#ffffff', '#ffffff'), 1);
    assert.equal(contrast('#777777', '#ffffff').toFixed(2), '4.48');
    assert.equal(contrast('#ffffff', '#777777'), contrast('#777777', '#ffffff'), 'order does not matter');
});

test('both themes define every token the checks need', () => {
    for (const theme of [dark, light]) {
        for (const name of [...BACKGROUNDS, ...TEXT, 'fg-faint', 'fg-dim']) {
            assert.match(theme[name] ?? '', /^#[0-9a-f]{6}$/, name);
        }
    }
    assert.notEqual(dark.surface, light.surface, 'the light block was read');
});

for (const [label, theme] of [['dark', dark], ['light', light]]) {
    test(`${label} theme: text tokens reach 4.5:1 on every background`, () => {
        assert.deepEqual(failures(theme, TEXT, 4.5), []);
    });

    test(`${label} theme: fg-faint icons reach 3:1 on every background`, () => {
        assert.deepEqual(failures(theme, ['fg-faint'], 3), []);
    });
}

test('white text on the accent and danger colours reaches 4.5:1', () => {
    // Buttons, the jump-to-latest pill, mention and unread badges (the same in both themes).
    for (const background of ['#5865f2', '#4752c4', '#da373c', '#248046']) {
        assert.ok(contrast('#ffffff', background) >= 4.5, background);
    }
});
