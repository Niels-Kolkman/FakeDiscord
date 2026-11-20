<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\MessageSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * GET /servers/{server}/search: search a server's messages, with
 * the text in ?q= and the filters ?channel={id}, ?author={user id},
 * ?from=Y-m-d and ?to=Y-m-d. Members only.
 *
 * Invalid input does not redirect back like a FormRequest would (a GET that
 * redirects to itself could loop): the page shows the errors and no results.
 */
class MessageSearchController extends Controller
{
    public function index(Request $request, Server $server): View
    {
        Gate::authorize('view', $server);

        /** @var User $user */
        $user = $request->user();

        // Only the text channels the member can see (ChannelPolicy::view):
        // the filter offers them and the search stays inside them.
        $channels = app(PermissionService::class)->visibleChannels($user, $server, $server->channels()
            ->where('type', '!=', Channel::TYPE_VOICE)
            ->orderBy('position')
            ->orderBy('id')
            ->get());

        $authors = $server->memberships()
            ->with('user')
            ->get()
            ->sortBy(fn (ServerMember $member): string => mb_strtolower($member->displayName()))
            ->values();

        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'max:'.MessageSearch::MAX_QUERY_LENGTH],
            'channel' => ['nullable', 'integer', Rule::in($channels->modelKeys())],
            // Any user, not only current members: people who left keep their messages.
            'author' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'channel.in' => __('Choose a text channel of this server.'),
            'author.exists' => __('That user does not exist.'),
            'to.after_or_equal' => __('The end date must be on or after the start date.'),
        ]);

        $search = new MessageSearch;
        $results = null;

        if (! $validator->fails()) {
            /** @var array{q?: string|null, channel?: int|string|null, author?: int|string|null, from?: string|null, to?: string|null} $input */
            $input = $validator->validated();

            $search = new MessageSearch(
                text: filled($input['q'] ?? null) ? (string) $input['q'] : null,
                channelId: filled($input['channel'] ?? null) ? (int) $input['channel'] : null,
                authorId: filled($input['author'] ?? null) ? (int) $input['author'] : null,
                from: filled($input['from'] ?? null) ? Carbon::createFromFormat('Y-m-d', (string) $input['from'])?->startOfDay() : null,
                to: filled($input['to'] ?? null) ? Carbon::createFromFormat('Y-m-d', (string) $input['to'])?->startOfDay() : null,
            );

            if (! $search->isEmpty()) {
                $results = $search->paginate($server, viewer: $user, channelIds: $channels->modelKeys())->withQueryString();
            }
        }

        return view('servers.search', [
            'server' => $server,
            'layout' => $server->channelLayout($user),
            'members' => $server->memberList(),
            'channels' => $channels,
            'authors' => $authors,
            'search' => $search,
            'results' => $results,
        ])->withErrors($validator);
    }
}
