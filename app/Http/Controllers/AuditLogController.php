<?php

namespace App\Http\Controllers;

use App\Models\AuditLogEntry;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * GET /servers/{server}/audit-log: the server's audit log, newest
 * first, 50 per page, filtered by ?action= and ?user= (who made the change).
 * Owner only; the route checks ServerPolicy::viewAuditLog.
 *
 * Like the search page, invalid filters do not redirect: the page shows the
 * errors and no entries.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request, Server $server): View
    {
        /** @var User $viewer */
        $viewer = $request->user();

        // Everyone who has made a change here, also people who have left.
        $actors = User::query()
            ->whereIn('id', $server->auditLogEntries()->select('actor_id'))
            ->orderBy('name')
            ->get();

        $validator = Validator::make($request->query(), [
            'action' => ['nullable', 'string', Rule::in(array_keys(AuditLogEntry::ACTIONS))],
            'user' => ['nullable', 'integer', Rule::in($actors->modelKeys())],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'action.in' => __('Choose an action from the list.'),
            'user.in' => __('Choose a user from the list.'),
        ]);

        $entries = null;

        if (! $validator->fails()) {
            /** @var array{action?: string|null, user?: int|string|null} $filters */
            $filters = $validator->validated();

            $entries = $server->auditLogEntries()
                ->with('actor')
                ->when(filled($filters['action'] ?? null), fn ($query) => $query->where('action', $filters['action']))
                ->when(filled($filters['user'] ?? null), fn ($query) => $query->where('actor_id', (int) $filters['user']))
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString();
        }

        return view('audit-log.index', [
            'server' => $server,
            'layout' => $server->channelLayout($viewer),
            'members' => $server->memberList(),
            'actions' => AuditLogEntry::ACTIONS,
            'actors' => $actors,
            'entries' => $entries,
        ])->withErrors($validator);
    }
}
