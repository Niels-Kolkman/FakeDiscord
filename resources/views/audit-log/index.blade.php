{{--
    The server audit log (ISSUE-30), from AuditLogController: who changed
    what, and when, newest first. Owner only. A plain GET form filters by
    action and by the user who made the change, so every filter has its own
    URL and works without JavaScript.

    @var \App\Models\Server $server
    @var array<string, string> $actions  action => label
    @var \Illuminate\Support\Collection<int, \App\Models\User> $actors
    @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\AuditLogEntry>|null $entries
--}}
@php
    $field = 'w-full rounded bg-[#1e1f22] px-3 py-2 text-white outline-none focus:ring-2 focus:ring-[#5865f2]';
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-[#b5bac1]';
    $value = fn (string $key): string => is_string(request()->query($key)) ? (string) request()->query($key) : '';
@endphp
<x-layouts.app :title="'Audit log · '.$server->name">
    <x-slot:sidebar>
        @include('servers.partials.sidebar', ['activeChannel' => null])
    </x-slot:sidebar>

    <x-slot:aside>
        @include('servers.partials.member-list')
    </x-slot:aside>

    <header class="flex min-h-12 items-center gap-2 border-b border-[#1f2023] px-4 py-2 shadow-sm">
        <span aria-hidden="true" class="text-xl text-[#80848e]">📜</span>
        <h1 class="font-semibold text-white">Audit log</h1>
    </header>

    <div class="min-h-0 flex-1 overflow-y-auto p-4">
        <form method="GET" action="{{ route('audit-log.index', $server) }}" class="mb-6 grid gap-4 sm:grid-cols-2" data-audit-log-filters>
            <div>
                <label for="audit-user" class="{{ $label }}">Filter by user</label>
                <select id="audit-user" name="user"
                    @error('user') aria-invalid="true" aria-describedby="audit-user-error" @enderror
                    class="{{ $field }}">
                    <option value="">All users</option>
                    @foreach ($actors as $actor)
                        <option value="{{ $actor->id }}" @selected($value('user') === (string) $actor->id)>{{ $actor->name }} ({{ '@'.$actor->username }})</option>
                    @endforeach
                </select>
                @error('user')
                    <p id="audit-user-error" class="mt-1 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="audit-action" class="{{ $label }}">Filter by action</label>
                <select id="audit-action" name="action"
                    @error('action') aria-invalid="true" aria-describedby="audit-action-error" @enderror
                    class="{{ $field }}">
                    <option value="">All actions</option>
                    @foreach ($actions as $action => $actionLabel)
                        <option value="{{ $action }}" @selected($value('action') === $action)>{{ $actionLabel }}</option>
                    @endforeach
                </select>
                @error('action')
                    <p id="audit-action-error" class="mt-1 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex gap-2 sm:col-span-2">
                <button type="submit" class="rounded bg-[#5865f2] px-4 py-2 text-sm font-medium text-white hover:bg-[#4752c4] focus:outline-none focus-visible:ring-2 focus-visible:ring-white">Filter</button>
                <a href="{{ route('audit-log.index', $server) }}" class="rounded px-4 py-2 text-sm text-[#b5bac1] hover:text-white hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5865f2]">Clear</a>
            </div>
        </form>

        @if ($entries !== null)
            @if ($entries->isEmpty())
                <p class="text-center text-[#949ba4]" role="status" data-audit-log-empty>
                    {{ $value('action') !== '' || $value('user') !== '' ? 'Nothing matches these filters.' : 'Nothing has been logged yet. Moderation and settings changes will show up here.' }}
                </p>
            @else
                <ol class="flex flex-col gap-2" aria-label="Audit log entries" data-audit-log>
                    @foreach ($entries as $entry)
                        <li class="flex gap-4 rounded-lg bg-[#2b2d31] p-3" data-audit-entry="{{ $entry->id }}" data-action="{{ $entry->action }}">
                            @if ($entry->actor)
                                <x-user-avatar :user="$entry->actor" :size="40" class="mt-0.5" />
                            @else
                                <span aria-hidden="true" class="mt-0.5 h-10 w-10 shrink-0 rounded-full bg-[#4e5058]"></span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="font-medium text-white">{{ $entry->actor?->name ?? 'Deleted User' }}</span>
                                    <span class="text-[#b5bac1]">{{ $entry->actionLabel() }}</span>
                                    @if ($entry->target_label !== null)
                                        <span class="font-medium text-white" data-audit-target>{{ $entry->target_type === 'channel' ? '#' : '' }}{{ $entry->target_label }}</span>
                                    @endif
                                    @if ($entry->created_at)
                                        <time class="shrink-0 text-xs text-[#949ba4]" datetime="{{ $entry->created_at->toIso8601String() }}" data-local-time>{{ $entry->created_at->format('Y-m-d H:i') }}</time>
                                    @endif
                                </p>
                                @if ($entry->changeLines() !== [])
                                    <ul class="mt-1 text-sm text-[#dbdee1]" data-audit-changes>
                                        @foreach ($entry->changeLines() as $line)
                                            <li class="break-words">{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($entry->reason !== null)
                                    <p class="mt-1 break-words text-sm text-[#b5bac1]" data-audit-reason>Reason: {{ $entry->reason }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>

                @if ($entries->hasPages())
                    <nav class="mt-4 flex items-center justify-between text-sm" aria-label="Audit log pages">
                        @if ($entries->onFirstPage())
                            <span class="text-[#6d6f78]">Newer</span>
                        @else
                            <a href="{{ $entries->previousPageUrl() }}" rel="prev" class="text-[#00a8fc] hover:underline">Newer</a>
                        @endif
                        <span class="text-[#949ba4]">Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }}</span>
                        @if ($entries->hasMorePages())
                            <a href="{{ $entries->nextPageUrl() }}" rel="next" class="text-[#00a8fc] hover:underline">Older</a>
                        @else
                            <span class="text-[#6d6f78]">Older</span>
                        @endif
                    </nav>
                @endif
            @endif
        @endif
    </div>
</x-layouts.app>
