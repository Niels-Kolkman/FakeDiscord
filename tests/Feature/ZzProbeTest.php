<?php

use App\Actions\Servers\CreateServer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function probeScene(object $test, int $members, int $messages): array
{
    $owner = User::factory()->create();
    $server = app(CreateServer::class)->create($owner, 'Probe');
    $channel = $server->channels()->firstOrFail();
    $role = $server->roles()->create(['name' => 'Mods', 'permissions' => 0, 'position' => 1, 'is_default' => false]);
    $users = [$owner];
    for ($i = 0; $i < $members; $i++) {
        $u = User::factory()->create();
        App\Models\ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $u->id]);
        if ($i % 2 === 0) {
            $role->users()->attach($u->id);
        }
        $users[] = $u;
    }
    $prev = null;
    for ($i = 0; $i < $messages; $i++) {
        $author = $users[$i % count($users)];
        $f = Message::factory()->inChannel($channel);
        if ($prev && $i % 3 === 0) {
            $f = $f->replyTo($prev);
        }
        $m = $f->create(['user_id' => $author->id, 'content' => "hi @{$users[0]->username} $i"]);
        Reaction::factory()->create(['message_id' => $m->id, 'user_id' => $users[($i + 1) % count($users)]->id, 'emoji' => '👍']);
        if ($i % 4 === 0) {
            MessageAttachment::factory()->create(['message_id' => $m->id]);
        }
        $prev = $m;
    }
    $dm = Conversation::factory()->between($owner, $users[1])->create();
    for ($i = 0; $i < $messages; $i++) {
        Message::factory()->inConversation($dm)->create(['user_id' => $users[$i % 2]->id]);
    }

    return [$owner, $server, $channel, $dm];
}

it('probes', function () {
    foreach ([[3, 3], [12, 30], [25, 60]] as [$members, $messages]) {
        [$owner, $server, $channel, $dm] = probeScene($this, $members, $messages);
        foreach ([
            "/servers/{$server->id}/channels/{$channel->id}",
            "/servers/{$server->id}/channels/{$channel->id}/messages",
            "/servers/{$server->id}",
            "/dms/{$dm->id}",
            "/dms/{$dm->id}/messages",
            "/servers/{$server->id}/channels/{$channel->id}/pins",
            '/dashboard',
        ] as $url) {
          foreach (['cold', 'warm'] as $pass) {
            if ($pass === 'cold') {
                app(App\Support\ServerCache::class)->flush();
            }
            $queries = [];
            DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });
            $status = $this->actingAs($owner)->get($url, ['Accept' => str_contains($url, 'messages') || str_contains($url, 'pins') ? 'application/json' : 'text/html'])->getStatusCode();
            DB::getEventDispatcher()->forget(Illuminate\Database\Events\QueryExecuted::class);
            $counts = array_count_values($queries);
            arsort($counts);
            fwrite(STDERR, sprintf("\n[%d/%d] %s %s -> %d, %d queries\n", $members, $messages, $pass, $url, $status, count($queries)));
            foreach (array_slice($counts, 0, 4, true) as $sql => $n) {
                if ($n > 1) {
                    fwrite(STDERR, "   x$n ".substr($sql, 0, 160)."\n");
                }
            }
          }
        }
    }
    expect(true)->toBeTrue();
});
