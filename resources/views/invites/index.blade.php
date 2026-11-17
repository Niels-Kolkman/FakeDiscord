<x-layouts.auth :title="'Invite people to '.$server->name">
    @if (session('invite_url'))
        <div role="status" class="mb-4 rounded bg-green-900/40 px-3 py-2 text-sm text-green-300">
            <p class="mb-2">Your invite link is ready. Share it with your friends:</p>
            <input type="text" readonly value="{{ session('invite_url') }}" aria-label="Invite link" onfocus="this.select()"
                class="w-full rounded bg-[#1e1f22] px-3 py-2 font-mono text-white outline-none focus:ring-2 focus:ring-[#5865f2]">
        </div>
    @endif

    <form method="POST" action="{{ route('servers.invites.store', $server) }}" class="mb-8">
        @csrf

        @foreach ([
            ['name' => 'expires_in', 'label' => 'Expire after', 'options' => $expiryOptions, 'default' => $defaultExpiry],
            ['name' => 'max_uses', 'label' => 'Max number of uses', 'options' => $maxUsesOptions, 'default' => 0],
        ] as $select)
            <div class="mb-4">
                <label for="{{ $select['name'] }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-[#b5bac1]">{{ $select['label'] }}</label>
                <select id="{{ $select['name'] }}" name="{{ $select['name'] }}"
                    @error($select['name']) aria-invalid="true" aria-describedby="{{ $select['name'] }}-error" @enderror
                    class="w-full rounded bg-[#1e1f22] px-3 py-2 text-white outline-none focus:ring-2 focus:ring-[#5865f2]">
                    @foreach ($select['options'] as $value => $label)
                        <option value="{{ $value }}" @selected((int) old($select['name'], $select['default']) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error($select['name'])
                    <p id="{{ $select['name'] }}-error" class="mt-1 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>
        @endforeach

        <button type="submit" class="w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Generate a new link</button>
    </form>

    <section aria-labelledby="invites-heading" class="mb-6">
        <h2 id="invites-heading" class="mb-2 text-xs font-semibold uppercase tracking-wide text-[#949ba4]">
            @can('manageInvites', $server) Active invites @else Your active invites @endcan
        </h2>

        @if ($invites->isEmpty())
            <p class="text-sm text-[#b5bac1]">No active invites.</p>
        @else
            <ul class="space-y-2">
                @foreach ($invites as $invite)
                    <li class="flex items-center justify-between gap-3 rounded bg-[#1e1f22] px-3 py-2 text-sm">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-white">{{ $invite->code }}</p>
                            <p class="text-xs text-[#949ba4]">
                                {{ $invite->inviter ? '@'.$invite->inviter->username : 'Unknown' }}
                                · {{ $invite->uses }}{{ $invite->max_uses !== null ? ' / '.$invite->max_uses : '' }} uses
                                · {{ $invite->expires_at ? 'expires '.$invite->expires_at->diffForHumans() : 'never expires' }}
                            </p>
                        </div>
                        @can('delete', $invite)
                            <form method="POST" action="{{ route('servers.invites.destroy', [$server, $invite]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded bg-[#da373c] px-2 py-1 text-xs font-medium text-white hover:bg-[#a12828]">Revoke</button>
                            </form>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="text-sm text-[#949ba4]">
        <a href="{{ route('servers.show', $server) }}" class="text-[#00a8fc] hover:underline">Back to server</a>
    </p>
</x-layouts.auth>
