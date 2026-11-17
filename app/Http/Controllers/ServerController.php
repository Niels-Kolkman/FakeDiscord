<?php

namespace App\Http\Controllers;

use App\Actions\Servers\CreateServer;
use App\Actions\Servers\UpdateServerIcon;
use App\Http\Requests\StoreServerRequest;
use App\Http\Requests\UpdateServerRequest;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Create, open, rename and delete servers. Authorisation lives in
 * App\Policies\ServerPolicy.
 */
class ServerController extends Controller
{
    public function create(): View
    {
        Gate::authorize('create', Server::class);

        return view('servers.create');
    }

    public function store(StoreServerRequest $request, CreateServer $servers): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $icon = $request->file('icon');

        $server = $servers->create(
            $user,
            $request->string('name')->value(),
            $icon instanceof UploadedFile ? $icon : null,
        );

        return redirect()->route('servers.show', $server)->with('status', 'Your server has been created.');
    }

    public function show(Server $server): View
    {
        Gate::authorize('view', $server);

        return view('servers.show', [
            'server' => $server,
            'channels' => $server->channels()->get(),
        ]);
    }

    public function edit(Server $server): View
    {
        Gate::authorize('update', $server);

        return view('servers.edit', ['server' => $server]);
    }

    public function update(UpdateServerRequest $request, Server $server, UpdateServerIcon $icons): RedirectResponse
    {
        // One transaction, so an unreadable icon also rolls back the rename.
        DB::transaction(function () use ($request, $server, $icons): void {
            $server->update(['name' => $request->string('name')->value()]);

            // A new upload wins over the "remove icon" checkbox.
            $icon = $request->file('icon');
            if ($icon instanceof UploadedFile) {
                $icons->update($server, $icon);
            } elseif ($request->boolean('remove_icon')) {
                $icons->remove($server);
            }
        });

        return redirect()->route('servers.edit', $server)->with('status', 'Server settings saved.');
    }

    /**
     * Deleting needs the server name typed again, like Discord, because it
     * also removes every channel, message, role, invite and membership.
     */
    public function destroy(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('delete', $server);

        if ($request->input('confirm_name') !== $server->name) {
            throw ValidationException::withMessages([
                'confirm_name' => 'Type the server name exactly to confirm.',
            ]);
        }

        $iconPath = $server->icon_path;

        // Child rows go through ON DELETE CASCADE.
        DB::transaction(fn () => $server->delete());

        if ($iconPath !== null) {
            Storage::disk(UpdateServerIcon::disk())->delete($iconPath);
        }

        return redirect()->route('dashboard')->with('status', "\"{$server->name}\" has been deleted.");
    }
}
