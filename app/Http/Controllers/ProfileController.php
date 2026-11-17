<?php

namespace App\Http\Controllers;

use App\Actions\Profile\UpdateUserAvatar;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

/**
 * The signed-in user's own profile settings: display name, bio and avatar.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(UpdateProfileRequest $request, UpdateUserAvatar $avatars): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->fill([
            'name' => $request->string('name')->value(),
            'bio' => $request->filled('bio') ? $request->string('bio')->value() : null,
        ])->save();

        // A new upload wins over the "remove avatar" checkbox.
        $avatar = $request->file('avatar');
        if ($avatar instanceof UploadedFile) {
            $avatars->update($user, $avatar);
        } elseif ($request->boolean('remove_avatar')) {
            $avatars->remove($user);
        }

        return redirect()->route('profile.edit')->with('status', 'Your profile has been updated.');
    }
}
