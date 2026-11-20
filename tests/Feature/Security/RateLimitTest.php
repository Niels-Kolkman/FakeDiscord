<?php

/**
 * ISSUE-41: the named rate limiters in App\Support\RateLimits. The
 * per-endpoint limits themselves (30 messages, 20 invites, 30 invite
 * lookups, 5 logins per email) are also covered by the endpoint tests;
 * these check what naming them changed: each limiter has its own budget,
 * channel and DM sends share one, and the per-IP limits on top.
 */

use App\Events\ConversationActivity;
use App\Events\DirectMessageReceived;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\User;
use App\Support\RateLimits;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([MessageSent::class, ConversationActivity::class, DirectMessageReceived::class]);
});

it('does not let other throttled routes use up the message budget', function () {
    $scene = serverScene();
    $base = "/servers/{$scene->server->id}/channels/{$scene->channel->id}";

    // Mention autocomplete (120/min) and read marks (120/min) used to
    // count in the same per-user bucket as sending (30/min).
    for ($i = 0; $i < 25; $i++) {
        $this->actingAs($scene->member)->getJson("{$base}/mentions?q=a")->assertOk();
        $this->actingAs($scene->member)->postJson("{$base}/read")->assertSuccessful();
    }

    $this->actingAs($scene->member)
        ->postJson(messagesUrl($scene->channel), ['content' => 'Still allowed'])
        ->assertCreated();
});

it('shares one sending budget between channels and DMs', function () {
    $scene = serverScene();
    $dm = Conversation::factory()->between($scene->member, $scene->owner)->create();

    for ($i = 0; $i < RateLimits::MESSAGES_PER_MINUTE - 10; $i++) {
        $this->actingAs($scene->member)
            ->postJson(messagesUrl($scene->channel), ['content' => "Channel {$i}"])
            ->assertCreated();
    }
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($scene->member)
            ->postJson("/dms/{$dm->id}/messages", ['content' => "DM {$i}"])
            ->assertCreated();
    }

    $this->actingAs($scene->member)
        ->postJson("/dms/{$dm->id}/messages", ['content' => 'One too many'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    // Per user: the other participant can still answer.
    $this->actingAs($scene->owner)
        ->postJson("/dms/{$dm->id}/messages", ['content' => 'Not affected'])
        ->assertCreated();
});

it('limits invite code lookups per IP across accounts', function () {
    $perUser = 20;
    $accounts = intdiv(RateLimits::INVITE_CODES_PER_IP, $perUser);

    // Below the per-user limit each, but together the IP's whole budget.
    for ($a = 0; $a < $accounts; $a++) {
        $guesser = User::factory()->create();
        for ($i = 0; $i < $perUser; $i++) {
            $this->actingAs($guesser)->get("/invite/guess{$a}x{$i}")->assertNotFound();
        }
    }

    $this->actingAs(User::factory()->create())->get('/invite/fresh')->assertTooManyRequests();

    // Another IP is not affected.
    $this->actingAs(User::factory()->create())
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('/invite/fresh')
        ->assertNotFound();
});

it('limits login attempts per IP across email addresses', function () {
    for ($i = 0; $i < RateLimits::LOGIN_PER_IP; $i++) {
        $this->post('/login', ['email' => "nobody{$i}@example.test", 'password' => 'wrong-password']);
    }

    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
    $this->assertGuest();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
});

it('uses the named limiters on the hardened routes', function (string $name, string $limiter) {
    expect(app('router')->getRoutes()->getByName($name)?->gatherMiddleware())
        ->toContain('throttle:'.$limiter);
})->with([
    'login' => ['login.store', RateLimits::LOGIN],
    'channel messages' => ['messages.store', RateLimits::MESSAGES],
    'DM messages' => ['dms.messages.store', RateLimits::MESSAGES],
    'creating invites' => ['servers.invites.store', RateLimits::INVITES],
    'opening invites' => ['invites.show', RateLimits::INVITE_CODES],
    'accepting invites' => ['invites.accept', RateLimits::INVITE_CODES],
]);
