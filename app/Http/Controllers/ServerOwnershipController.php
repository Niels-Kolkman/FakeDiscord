<?php

namespace App\Http\Controllers;

use App\Actions\Servers\TransferServerOwnership;
use App\Http\Requests\TransferServerOwnershipRequest;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

/**
 * Transfer ownership, from the server settings page. Needed before the
 * owner can leave.
 */
class ServerOwnershipController extends Controller
{
    public function update(TransferServerOwnershipRequest $request, Server $server, TransferServerOwnership $ownership): RedirectResponse
    {
        $newOwner = $request->newOwner();

        $ownership->transfer($server, $newOwner);

        // The previous owner can no longer open the settings page.
        return redirect()
            ->route('servers.show', $server)
            ->with('status', "{$newOwner->name} now owns \"{$server->name}\".");
    }
}
