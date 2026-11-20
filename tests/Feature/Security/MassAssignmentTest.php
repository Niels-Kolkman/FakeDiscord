<?php

/**
 * Mass-assignment audit. Every model lists its $fillable columns
 * (nothing is unguarded), and the columns that carry trust are not among
 * them: they are only ever set with forceFill() by the code that owns
 * them. The endpoints are covered where they live (RegistrationTest,
 * ServerManagementEdgeCasesTest, InviteEdgeCasesTest, SendMessageTest,
 * MessageBroadcastEdgeCasesTest, ChannelManagementTest, MentionsTest);
 * the profile form is checked here.
 */

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * @return list<class-string<Model>>
 */
function appModels(): array
{
    return collect(File::files(app_path('Models')))
        ->map(fn ($file) => 'App\\Models\\'.Str::beforeLast($file->getFilename(), '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, Model::class))
        ->values()
        ->all();
}

it('guards every model with an explicit fillable list', function () {
    expect(appModels())->not->toBeEmpty();
    expect(Model::isUnguarded())->toBeFalse();

    foreach (appModels() as $class) {
        $model = new $class;

        expect($model->getFillable())->not->toBeEmpty("{$class} has no \$fillable")
            ->and($model->getGuarded())->toBe(['*'], "{$class} changes \$guarded")
            ->and($model->isFillable($model->getKeyName()))->toBeFalse("{$class} lets its key be filled");
    }
});

it('keeps trusted columns out of fillable', function (Model $model, array $columns) {
    foreach ($columns as $column) {
        expect($model->isFillable($column))->toBeFalse($model::class."::\${$column} is fillable");
    }
})->with([
    'users' => [fn (): Model => new User, ['email_verified_at', 'remember_token', 'created_at']],
    'messages' => [fn (): Model => new Message, ['pinned_at', 'pinned_by_id', 'mentions', 'deleted_at']],
]);

it('only changes the name and bio through the profile form', function () {
    $user = User::factory()->create(['username' => 'original', 'email' => 'original@example.test']);
    $guarded = ['username', 'email', 'email_verified_at', 'password', 'avatar_path', 'status', 'theme'];
    $before = Arr::only($user->fresh()?->getAttributes() ?? [], $guarded);

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => 'New Name',
        'bio' => 'New bio',
        'username' => 'taken_over',
        'email' => 'attacker@example.test',
        'email_verified_at' => null,
        'password' => 'new-password',
        'avatar_path' => '../../.env',
        'status' => 'dnd',
        'theme' => 'light',
    ])->assertRedirect('/settings/profile');

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->bio)->toBe('New bio')
        ->and(Arr::only($user->getAttributes(), $guarded))->toBe($before);
});
