/**
 * Steps of the browser tests, written against what a user sees:
 * labels, button names and the message list.
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';

import { expect } from '@playwright/test';

import { ROOT, phpEnv } from './environment.js';

export const PASSWORD = 'e2e-correct-horse-battery';

let counter = 0;

/** A user nobody has registered yet, so reruns against a kept database work too. */
export function newUser(name) {
    const id = `${Date.now().toString(36)}${(counter++).toString(36)}`;
    const handle = `${name.toLowerCase()}_${id}`;

    return { name, username: handle, email: `${handle}@example.test`, password: PASSWORD };
}

export async function register(page, user) {
    await page.goto('/register');
    await page.getByLabel('Email').fill(user.email);
    await page.getByLabel('Display name').fill(user.name);
    await page.getByLabel('Username').fill(user.username);
    await page.getByLabel('Password', { exact: true }).fill(user.password);
    await page.getByLabel('Confirm password').fill(user.password);
    await page.getByRole('button', { name: 'Continue' }).click();

    await expect(page).toHaveURL(/\/dashboard/);
}

/**
 * Opens the link from the verification email. The URL is made by the
 * app's own VerifyEmail notification (support/verification-url.php).
 */
export async function verifyEmail(page, user) {
    const url = execFileSync(process.env.PHP_BINARY ?? 'php', [path.join('tests', 'e2e', 'support', 'verification-url.php'), user.email], {
        cwd: ROOT,
        env: phpEnv(),
        encoding: 'utf8',
    }).trim();

    await page.goto(url);
    await expect(page).toHaveURL(/\/dashboard/);
}

/** The message panel of the open channel or DM. */
export function messagePanel(page) {
    return page.locator('[data-channel-messages]');
}

/**
 * Waits until the page's websocket is connected and subscribed to the
 * panel's private channel: only then can it receive broadcasts. Without
 * this a message sent right after the other page loads can be missed.
 */
export async function waitForRealtime(page) {
    const channel = await messagePanel(page).getAttribute('data-broadcast-channel');
    expect(channel, 'the message panel names its broadcast channel').toBeTruthy();

    const name = `private-${channel}`;
    try {
        await page.waitForFunction((name) => {
            const pusher = window.Echo?.connector?.pusher;

            return pusher?.connection?.state === 'connected' && pusher.channel(name)?.subscribed === true;
        }, name, { timeout: 15_000 });
    } catch (error) {
        const state = await page.evaluate(() => window.Echo?.connector?.pusher?.connection?.state ?? 'no Echo');
        throw new Error(`Not subscribed to ${name} (websocket: ${state}). Is Reverb running and does /broadcasting/auth work?`, { cause: error });
    }
}

/** Types a message and sends it with Enter, like a user does. */
export async function sendMessage(page, text) {
    const composer = messagePanel(page).locator('[data-message-input]');
    await composer.fill(text);
    await composer.press('Enter');
    await expect(composer).toHaveValue('');
}

/** A message in the panel's list, by its text. */
export function message(page, text) {
    return messagePanel(page).locator('[data-message-list] [data-message]').filter({ hasText: text });
}

/**
 * Marks the current document, so a test can prove that something showed
 * up without a reload or navigation (the mark is gone after either).
 */
export async function markDocument(page) {
    await page.evaluate(() => {
        window.__e2eDocumentMark = true;
    });
}

export async function expectSameDocument(page) {
    expect(await page.evaluate(() => window.__e2eDocumentMark === true), 'the page was not reloaded').toBe(true);
}
