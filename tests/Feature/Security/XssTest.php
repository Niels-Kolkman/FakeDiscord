<?php

/**
 * ISSUE-41: XSS review of the pages that show what users typed. Message
 * markdown itself is covered by MarkdownTest / MessageMarkdownTest (the
 * renderer only writes its own tags and escapes everything else); these
 * put a payload in every other user-controlled string on the main pages
 * and check it never comes out as markup.
 */

use App\Models\Conversation;
use App\Models\Invite;
use App\Models\Message;
use App\Models\ServerMember;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

const XSS_PAYLOAD = '"><script>alert(1)</script><img src=x onerror=alert(2)>';

/**
 * @param  TestResponse<Response>  $response
 */
function assertNoInjectedMarkup(TestResponse $response): void
{
    $response->assertOk()
        // The payload is on the page, as text.
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)', false)
        ->assertDontSee('<img src=x onerror', false)
        ->assertDontSee('"><script', false);
}

it('escapes names, nicknames, the server name and the topic on a channel page', function () {
    $scene = serverScene();
    $scene->server->update(['name' => 'Server '.XSS_PAYLOAD]);
    $scene->channel->update(['topic' => 'Topic '.XSS_PAYLOAD]);
    $scene->member->forceFill(['name' => 'Name '.XSS_PAYLOAD])->save();
    ServerMember::query()->where('user_id', $scene->owner->id)->update(['nickname' => 'Nick '.XSS_PAYLOAD]);

    Message::factory()->create(['channel_id' => $scene->channel->id, 'user_id' => $scene->member->id, 'content' => XSS_PAYLOAD]);
    Message::factory()->create(['channel_id' => $scene->channel->id, 'user_id' => $scene->owner->id, 'content' => 'hi']);

    $response = $this->actingAs($scene->member)
        ->get("/servers/{$scene->server->id}/channels/{$scene->channel->id}");

    assertNoInjectedMarkup($response);
    $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('never links javascript: or data: URLs in messages', function () {
    $scene = serverScene();
    Message::factory()->create([
        'channel_id' => $scene->channel->id,
        'user_id' => $scene->member->id,
        'content' => 'javascript:alert(1) data:text/html,<b>x</b> <javascript:alert(1)> https://ok.example/"onmouseover="alert(1)',
    ]);

    $this->actingAs($scene->member)
        ->get("/servers/{$scene->server->id}/channels/{$scene->channel->id}")
        ->assertOk()
        ->assertDontSee('href="javascript:', false)
        ->assertDontSee('href="data:', false)
        ->assertDontSee('"onmouseover="', false)
        // The quote stays inside the (escaped) href of a plain https link.
        ->assertSee('href="https://ok.example/&quot;onmouseover=&quot;alert(1"', false);
});

it('escapes the search query and its highlighted matches', function () {
    $scene = serverScene();
    Message::factory()->create(['channel_id' => $scene->channel->id, 'user_id' => $scene->member->id, 'content' => 'before '.XSS_PAYLOAD.' after']);

    $response = $this->actingAs($scene->member)
        ->get("/servers/{$scene->server->id}/search?q=".urlencode('<script>alert(1)</script>'));

    assertNoInjectedMarkup($response);
    $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;</mark>', false);
});

it('escapes a group DM name and its people', function () {
    $scene = serverScene();
    $group = Conversation::factory()->group()->create(['name' => 'Group '.XSS_PAYLOAD, 'owner_id' => $scene->member->id]);
    $group->participants()->createMany([['user_id' => $scene->member->id], ['user_id' => $scene->owner->id]]);
    $scene->owner->forceFill(['name' => 'Owner '.XSS_PAYLOAD])->save();

    assertNoInjectedMarkup($this->actingAs($scene->member)->get("/dms/{$group->id}"));
});

it('escapes the server on an invite page', function () {
    $scene = serverScene();
    $scene->server->update(['name' => 'Server '.XSS_PAYLOAD]);
    $invite = Invite::factory()->create(['server_id' => $scene->server->id, 'inviter_id' => $scene->owner->id]);
    $scene->owner->forceFill(['name' => 'Inviter '.XSS_PAYLOAD])->save();

    assertNoInjectedMarkup($this->actingAs(memberOf(serverOwnedBy()))->get("/invite/{$invite->code}"));
});
