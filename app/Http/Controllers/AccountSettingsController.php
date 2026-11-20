<?php

namespace App\Http\Controllers;

use App\Actions\Account\DeleteUser;
use App\Actions\Account\UpdateUserEmail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Account settings: email, password and account deletion.
 * The password form posts to Fortify's PUT /user/password.
 */
class AccountSettingsController extends Controller
{
    public function show(): View
    {
        return view('settings.account');
    }

    public function updateEmail(Request $request, UpdateUserEmail $updater): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $changed = $updater->update($user, $request->only(['email', 'current_password']));

        return redirect()->route('settings.account')
            ->with('status', $changed ? 'email-updated' : 'email-unchanged');
    }

    public function destroy(Request $request, DeleteUser $deleter): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleter->delete($user, $request->only('password'));

        // Not logout(): it cycles the remember token, which save()s the user
        // and would re-insert the row we just deleted.
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'account-deleted');
    }
}
