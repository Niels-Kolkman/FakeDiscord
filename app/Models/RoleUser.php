<?php

namespace App\Models;

use Database\Factories\RoleUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A role held by a member (the role_user table). Role::users() and
 * User::roles() use it, so attach(), detach() and sync() fire model events
 * and App\Observers\ForgetsServerCache can drop the server's cached
 * permissions and member list (ISSUE-42).
 *
 * @property int $role_id
 * @property int $user_id
 */
class RoleUser extends Pivot
{
    /** @use HasFactory<RoleUserFactory> */
    use HasFactory;

    protected $table = 'role_user';

    /**
     * @var list<string>
     */
    protected $fillable = ['role_id', 'user_id'];

    /**
     * Pivot unguards everything; like the other models, only $fillable.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];
}
