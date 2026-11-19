<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The theme toggle (ISSUE-37, <x-theme-toggle>): dark or light, saved on
 * the user so it follows them to every browser. A plain form redirects
 * back; resources/js/theme.js switches the page at once and sends it with
 * Accept: JSON, getting {theme} instead.
 */
class ThemeController extends Controller
{
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(User::THEMES)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['theme' => $validated['theme']])->save();

        if ($request->expectsJson()) {
            return response()->json(['theme' => $user->preferredTheme()]);
        }

        return back()->with('status', 'Switched to the '.$user->preferredTheme().' theme.');
    }
}
