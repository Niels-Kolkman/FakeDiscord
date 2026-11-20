<?php

namespace Tests\Unit;

use App\Enums\Permission;
use PHPUnit\Framework\TestCase;

/**
 * The permission flags and their bitmask helpers.
 */
class PermissionTest extends TestCase
{
    public function test_every_flag_is_a_single_bit_within_bits_0_to_62(): void
    {
        $seen = 0;

        foreach (Permission::cases() as $permission) {
            $value = $permission->value;
            $this->assertGreaterThan(0, $value, $permission->name);
            $this->assertSame(0, $value & ($value - 1), "{$permission->name} is one bit");
            $this->assertLessThanOrEqual(1 << 62, $value, "{$permission->name} fits a signed 64-bit integer");
            $this->assertSame(0, $seen & $value, "{$permission->name} is not shared");
            $seen |= $value;

            $this->assertNotSame('', $permission->label());
            $this->assertNotSame('', $permission->description());
        }

        $this->assertSame($seen, Permission::all());
    }

    public function test_the_values_match_discord(): void
    {
        $this->assertSame(0x1, Permission::CreateInvite->value);
        $this->assertSame(0x2, Permission::KickMembers->value);
        $this->assertSame(0x4, Permission::BanMembers->value);
        $this->assertSame(0x8, Permission::Administrator->value);
        $this->assertSame(0x10, Permission::ManageChannels->value);
        $this->assertSame(0x2000, Permission::ManageMessages->value);
        $this->assertSame(0x10000000, Permission::ManageRoles->value);
    }

    public function test_masks_round_trip(): void
    {
        $mask = Permission::mask(Permission::BanMembers, Permission::CreateInvite, Permission::BanMembers);

        $this->assertSame(0b101, $mask);
        $this->assertSame([Permission::CreateInvite, Permission::BanMembers], Permission::fromMask($mask));
        $this->assertSame(0, Permission::mask());
        $this->assertSame([], Permission::fromMask(0));
        // Unknown bits are ignored.
        $this->assertSame([Permission::KickMembers], Permission::fromMask((1 << 61) | Permission::KickMembers->value));

        $this->assertTrue(Permission::BanMembers->in($mask));
        $this->assertFalse(Permission::KickMembers->in($mask));
    }

    public function test_everyone_gets_what_every_member_could_already_do(): void
    {
        $this->assertSame(
            [Permission::CreateInvite, Permission::AddReactions, Permission::ViewChannels, Permission::SendMessages, Permission::AttachFiles],
            Permission::fromMask(Permission::everyoneDefaults()),
        );
        // The data migration copies this value.
        $this->assertSame(35905, Permission::everyoneDefaults());
    }
}
