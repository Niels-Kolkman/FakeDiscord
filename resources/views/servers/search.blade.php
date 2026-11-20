{{--
    Message search in a server, from MessageSearchController.
    A plain GET form, so it works without JavaScript and every search has
    its own URL. Results are newest first, 25 per page
    (App\Support\MessageSearch), with the matches in <mark>.

    @var \App\Models\Server $server
    @var \Illuminate\Support\Collection<int, \App\Models\Channel> $channels
    @var \Illuminate\Support\Collection<int, \App\Models\ServerMember> $authors
    @var \App\Support\MessageSearch $search
    @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Message>|null $results
--}}
@php
    $field = 'w-full rounded bg-surface-3 px-3 py-2 text-fg-strong outline-none focus:ring-2 focus:ring-[#5865f2]';
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-fg-muted';
    // Refill the form from the query string, also when it did not validate.
    $value = fn (string $key): string => is_string(request()->query($key)) ? (string) request()->query($key) : '';
@endphp
<x-layouts.app :title="'Search · '.$server->name">
    <x-slot:sidebar>
        @include('servers.partials.sidebar', ['activeChannel' => null])
    </x-slot:sidebar>

    <x-slot:aside>
        @include('servers.partials.member-list')
    </x-slot:aside>

    <header class="flex min-h-12 items-center gap-2 border-b border-edge px-4 py-2 shadow-sm">
        <span aria-hidden="true" class="text-xl text-fg-faint">🔍</span>
        <h1 class="font-semibold text-fg-strong">Search {{ $server->name }}</h1>
    </header>

    <div class="min-h-0 flex-1 overflow-y-auto p-4">
        <form method="GET" action="{{ route('servers.search', $server) }}" role="search" class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-message-search-form>
            <div class="sm:col-span-2 lg:col-span-4">
                <label for="search-q" class="{{ $label }}">Search messages</label>
                <input id="search-q" type="search" name="q" value="{{ $value('q') }}"
                    maxlength="{{ \App\Support\MessageSearch::MAX_QUERY_LENGTH }}"
                    placeholder="Search"
                    autocomplete="off"
                    @error('q') aria-invalid="true" aria-describedby="search-q-error" @enderror
                    class="{{ $field }}">
                @error('q')
                    <p id="search-q-error" class="mt-1 text-sm text-danger-fg">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="search-channel" class="{{ $label }}">In channel</label>
                <select id="search-channel" name="channel"
                    @error('channel') aria-invalid="true" aria-describedby="search-channel-error" @enderror
                    class="{{ $field }}">
                    <option value="">All channels</option>
                    @foreach ($channels as $option)
                        <option value="{{ $option->id }}" @selected($value('channel') === (string) $option->id)>#{{ $option->name }}</option>
                    @endforeach
                </select>
                @error('channel')
                    <p id="search-channel-error" class="mt-1 text-sm text-danger-fg">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="search-author" class="{{ $label }}">From</label>
                <select id="search-author" name="author"
                    @error('author') aria-invalid="true" aria-describedby="search-author-error" @enderror
                    class="{{ $field }}">
                    <option value="">Anyone</option>
                    @foreach ($authors as $member)
                        <option value="{{ $member->user_id }}" @selected($value('author') === (string) $member->user_id)>{{ $member->displayName() }} ({{ '@'.$member->user?->username }})</option>
                    @endforeach
                </select>
                @error('author')
                    <p id="search-author-error" class="mt-1 text-sm text-danger-fg">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="search-from" class="{{ $label }}">On or after</label>
                <input id="search-from" type="date" name="from" value="{{ $value('from') }}"
                    @error('from') aria-invalid="true" aria-describedby="search-from-error" @enderror
                    class="{{ $field }} [color-scheme:dark]">
                @error('from')
                    <p id="search-from-error" class="mt-1 text-sm text-danger-fg">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="search-to" class="{{ $label }}">On or before</label>
                <input id="search-to" type="date" name="to" value="{{ $value('to') }}"
                    @error('to') aria-invalid="true" aria-describedby="search-to-error" @enderror
                    class="{{ $field }} [color-scheme:dark]">
                @error('to')
                    <p id="search-to-error" class="mt-1 text-sm text-danger-fg">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
                <button type="submit" class="rounded bg-[#5865f2] px-4 py-2 text-sm font-medium text-white hover:bg-[#4752c4] focus:outline-none focus-visible:ring-2 focus-visible:ring-white">Search</button>
                <a href="{{ route('servers.search', $server) }}" class="rounded px-4 py-2 text-sm text-fg-muted hover:text-fg-strong hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]">Clear</a>
            </div>
        </form>

        @if ($results === null)
            @unless ($errors->any())
                <p class="text-center text-fg-subtle" data-search-hint>Type something to search for, or pick a channel, an author or dates.</p>
            @endunless
        @else
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-fg-muted" role="status" data-search-count>
                {{ trans_choice('{0} No results|{1} :count result|[2,*] :count results', $results->total(), ['count' => number_format($results->total())]) }}
            </h2>

            @if ($results->isNotEmpty())
                <ol class="flex flex-col gap-2" aria-label="Search results" data-search-results>
                    @foreach ($results as $message)
                        @php($messageChannel = $message->channel)
                        <li class="flex gap-4 rounded-lg bg-surface-2 p-3" data-search-result data-message-id="{{ $message->id }}">
                            @if ($message->author)
                                <x-user-avatar :user="$message->author" :size="40" class="mt-0.5" />
                            @else
                                <span aria-hidden="true" class="mt-0.5 h-10 w-10 shrink-0 rounded-full bg-[#4e5058]"></span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="truncate font-medium text-fg-strong">{{ $message->authorName() }}</span>
                                    <time class="shrink-0 text-xs text-fg-subtle" datetime="{{ $message->created_at?->toIso8601String() }}" data-local-time>{{ $message->created_at?->format('Y-m-d H:i') }}</time>
                                    @if ($messageChannel)
                                        <a href="{{ route('channels.show', [$server, $messageChannel]) }}" class="text-xs text-link hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]" data-search-result-channel>#{{ $messageChannel->name }}</a>
                                    @endif
                                </p>
                                <p class="whitespace-pre-wrap break-words text-fg" data-search-result-content>{{ \App\Support\MessageSearch::highlight((string) $message->content, $search->text) }}@if ($message->isEdited())<span class="ml-1 text-xs text-fg-subtle">(edited)</span>@endif</p>
                            </div>
                        </li>
                    @endforeach
                </ol>

                @if ($results->hasPages())
                    <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Search result pages">
                        @if ($results->onFirstPage())
                            <span class="text-fg-dim">Newer</span>
                        @else
                            <a href="{{ $results->previousPageUrl() }}" rel="prev" class="text-link hover:underline">Newer</a>
                        @endif
                        <span class="text-fg-subtle">Page {{ $results->currentPage() }} of {{ $results->lastPage() }}</span>
                        @if ($results->hasMorePages())
                            <a href="{{ $results->nextPageUrl() }}" rel="next" class="text-link hover:underline">Older</a>
                        @else
                            <span class="text-fg-dim">Older</span>
                        @endif
                    </nav>
                @endif
            @endif
        @endif
    </div>
</x-layouts.app>
