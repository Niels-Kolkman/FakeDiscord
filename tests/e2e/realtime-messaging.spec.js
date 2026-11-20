/**
 * The critical flow in real browsers: a new user registers,
 * verifies their email, creates a server and invites a second user, who
 * registers and joins; then the two exchange messages in #general, each
 * in their own browser session, live over Reverb.
 */
import { expect, test } from '@playwright/test';

import {
    expectSameDocument,
    markDocument,
    message,
    messagePanel,
    newUser,
    register,
    sendMessage,
    verifyEmail,
    waitForRealtime,
} from './support/app.js';

test('register, create a server, invite a friend and chat in real time', async ({ browser }) => {
    // Two contexts: separate cookies and sessions, like two people.
    const aliceContext = await browser.newContext();
    const bobContext = await browser.newContext();
    const alicePage = await aliceContext.newPage();
    const bobPage = await bobContext.newPage();

    const alice = newUser('Alice');
    const bob = newUser('Bob');
    const serverName = `E2E Guild ${alice.username.slice(-6)}`;
    let inviteUrl;

    await test.step('Alice registers and verifies her email', async () => {
        await register(alicePage, alice);
        await verifyEmail(alicePage, alice);
    });

    await test.step('Alice creates a server', async () => {
        await alicePage.getByRole('link', { name: 'Create a server' }).first().click();
        await alicePage.getByLabel('Server name').fill(serverName);
        await alicePage.getByRole('button', { name: 'Create' }).click();

        await expect(alicePage).toHaveURL(/\/servers\/\d+$/);
        await expect(alicePage.getByText('Your server has been created.')).toBeVisible();
        await expect(messagePanel(alicePage).locator('[data-message-input]')).toHaveAttribute('placeholder', 'Message #general');
    });

    await test.step('Alice creates an invite link', async () => {
        await alicePage.getByRole('link', { name: 'Invite people' }).click();
        await alicePage.getByRole('button', { name: 'Generate a new link' }).click();

        inviteUrl = await alicePage.getByLabel('Invite link').inputValue();
        expect(inviteUrl).toMatch(/\/invite\/[A-Za-z0-9]+$/);

        await alicePage.getByRole('link', { name: 'Back to server' }).click();
        await expect(alicePage).toHaveURL(/\/servers\/\d+$/);
    });

    await test.step('Bob registers, verifies his email and accepts the invite', async () => {
        await register(bobPage, bob);
        await verifyEmail(bobPage, bob);

        await bobPage.goto(inviteUrl);
        await expect(bobPage.getByText(`@${alice.username} invited you to join`)).toBeVisible();
        await expect(bobPage.getByText(serverName, { exact: true })).toBeVisible();
        await bobPage.getByRole('button', { name: 'Accept invite' }).click();

        await expect(bobPage).toHaveURL(/\/servers\/\d+$/);
        await expect(messagePanel(bobPage).locator('[data-message-input]')).toHaveAttribute('placeholder', 'Message #general');
    });

    await test.step('Both sessions are listening on #general', async () => {
        await waitForRealtime(alicePage);
        await waitForRealtime(bobPage);
        await markDocument(alicePage);
        await markDocument(bobPage);
    });

    await test.step('Alice sends a message and Bob sees it live', async () => {
        const text = `Hi Bob, welcome to ${serverName}!`;
        await sendMessage(alicePage, text);

        await expect(message(alicePage, text)).toHaveCount(1);
        await expect(message(bobPage, text)).toHaveCount(1);
        await expect(message(bobPage, text)).toContainText(alice.name);
        await expectSameDocument(bobPage);
    });

    await test.step('Bob replies and Alice sees it live', async () => {
        const text = 'Thanks Alice, glad to be here.';
        await sendMessage(bobPage, text);

        await expect(message(bobPage, text)).toHaveCount(1);
        await expect(message(alicePage, text)).toHaveCount(1);
        await expect(message(alicePage, text)).toContainText(bob.name);
        await expectSameDocument(alicePage);
    });

    await test.step('Both see the same conversation in the same order', async () => {
        const texts = (page) => messagePanel(page).locator('[data-message-list] [data-message-content]').allInnerTexts();

        await expect.poll(() => texts(alicePage)).toHaveLength(2);
        expect(await texts(bobPage)).toEqual(await texts(alicePage));
    });

    await aliceContext.close();
    await bobContext.close();
});
