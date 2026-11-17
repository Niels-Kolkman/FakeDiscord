<?php

namespace Tests\Feature\Servers;

use App\Actions\Servers\CreateServer;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Policies\ServerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * ISSUE-10: ServerPolicy abilities and the server icon fallback.
 */
class ServerPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_policy_is_registered_for_servers(): void
    {
        $this->assertInstanceOf(ServerPolicy::class, Gate::getPolicyFor(Server::class));
    }

    public function test_abilities_per_role(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $server = app(CreateServer::class)->create($owner, 'Policy Test');
        ServerMember::factory()->create(['server_id' => $server->id, 'user_id' => $member->id]);

        $expected = [
            // [user, view, update, delete]
            [$owner, true, true, true],
            [$member, true, false, false],
            [$stranger, false, false, false],
        ];

        foreach ($expected as [$user, $view, $update, $delete]) {
            $this->assertSame($view, $user->can('view', $server), "view for {$user->username}");
            $this->assertSame($update, $user->can('update', $server), "update for {$user->username}");
            $this->assertSame($delete, $user->can('delete', $server), "delete for {$user->username}");
        }
    }

    public function test_only_verified_users_can_create(): void
    {
        $this->assertTrue(User::factory()->create()->can('create', Server::class));
        $this->assertFalse(User::factory()->unverified()->create()->can('create', Server::class));
    }

    public function test_a_transferred_server_follows_the_new_owner(): void
    {
        $old = User::factory()->create();
        $new = User::factory()->create();
        $server = app(CreateServer::class)->create($old, 'Handover');

        $server->forceFill(['owner_id' => $new->id])->save();

        $this->assertFalse($old->can('delete', $server));
        $this->assertTrue($new->can('delete', $server));
    }

    public function test_initials_fallback(): void
    {
        $cases = [
            'Gaming' => 'G',
            'my cool server' => 'mcs',
            'One Two Three Four' => 'OTT',
            'Élan  Vital' => 'ÉV',
        ];

        foreach ($cases as $name => $initials) {
            $this->assertSame($initials, (new Server(['name' => $name]))->initials());
        }

        $html = Blade::render('<x-server-icon :server="$server" :size="48" />', ['server' => new Server(['name' => '<b>Bold</b> Move'])]);
        $this->assertStringContainsString('aria-label="&lt;b&gt;Bold&lt;/b&gt; Move"', $html);
        $this->assertStringContainsString('&lt;M', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }
}
