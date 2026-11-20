{{--
    A channel's permissions: the private toggle and, per role,
    allow / inherit / deny for each channel-overridable flag. Roles the
    viewer cannot manage (RolePolicy::manage) are shown read-only.

    @var \App\Models\Server $server
    @var \App\Models\Channel $channel
    @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
    @var \Illuminate\Support\Collection<int, \App\Models\ChannelPermissionOverride> $overrides keyed by role id
    @var list<\App\Enums\Permission> $flags
    @var list<int> $manageable role ids
--}}
@php
    $stateLabels = [
        \App\Models\ChannelPermissionOverride::ALLOW => 'Allow',
        \App\Models\ChannelPermissionOverride::INHERIT => 'Inherit',
        \App\Models\ChannelPermissionOverride::DENY => 'Deny',
    ];
    $failedRole = (int) old('role_id');
@endphp
<x-layouts.auth :title="'Permissions: #'.$channel->name">
    <form method="POST" action="{{ route('channels.privacy.update', [$server, $channel]) }}" id="channel-privacy">
        @csrf
        @method('PATCH')
        <input type="hidden" name="is_private" value="0">

        <h2 class="mb-2 font-semibold text-fg-strong">Private channel</h2>
        <label class="mb-2 flex items-start gap-3 text-sm text-fg">
            <input type="checkbox" name="is_private" value="1" class="mt-1 accent-[#5865f2]" @checked($channel->is_private)>
            <span>
                Only roles allowed <strong>View Channels</strong> below can see this channel.
                The owner and Administrators always can.
            </span>
        </label>
        @error('is_private')
            <p class="mb-2 text-sm text-danger-fg">{{ $message }}</p>
        @enderror
        <button type="submit" class="mb-8 w-full rounded bg-[#5865f2] py-2 font-medium text-white hover:bg-[#4752c4]">Save privacy</button>
    </form>

    <section aria-labelledby="role-overrides-heading" class="border-t border-line pt-6">
        <h2 id="role-overrides-heading" class="mb-2 font-semibold text-fg-strong">Role permissions</h2>
        <p class="mb-4 text-sm text-fg-muted">
            Override what a role may do in #{{ $channel->name }}. Inherit uses the role's server permissions.
            An allow on any of a member's roles beats a deny on another.
        </p>

        <ul class="space-y-4">
            @foreach ($roles as $role)
                @php
                    $override = $overrides->get($role->id);
                    $canEdit = in_array($role->id, $manageable, true);
                    $failed = $failedRole === $role->id;
                @endphp
                <li class="rounded bg-surface-2 p-3" data-role-override="{{ $role->id }}">
                    <form method="POST" action="{{ route('channels.permissions.update', [$server, $channel, $role]) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="role_id" value="{{ $role->id }}">

                        <p class="mb-2 font-medium text-fg-strong" @if ($role->displayColor()) style="color: {{ $role->displayColor() }}" @endif>{{ $role->name }}</p>

                        @foreach ($flags as $flag)
                            @php
                                $state = $failed
                                    ? old('permissions.'.$flag->name, \App\Models\ChannelPermissionOverride::INHERIT)
                                    : ($override?->stateOf($flag) ?? \App\Models\ChannelPermissionOverride::INHERIT);
                            @endphp
                            <fieldset class="mb-2 flex flex-wrap items-center gap-3 text-sm" @disabled(! $canEdit)>
                                <legend class="float-left w-36 text-fg">{{ $flag->label() }}</legend>
                                @foreach ($stateLabels as $value => $label)
                                    <label class="flex items-center gap-1 text-fg-muted">
                                        <input type="radio" name="permissions[{{ $flag->name }}]" value="{{ $value }}"
                                            class="accent-[#5865f2]" @checked($state === $value)>
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </fieldset>
                            @if ($failed && $errors->has('permissions.'.$flag->name))
                                <p class="mb-2 text-sm text-danger-fg">{{ $errors->first('permissions.'.$flag->name) }}</p>
                            @endif
                        @endforeach

                        @if ($canEdit)
                            <button type="submit" class="mt-1 rounded bg-[#5865f2] px-3 py-1 text-sm font-medium text-white hover:bg-[#4752c4]">Save</button>
                        @else
                            <p class="text-xs text-fg-subtle">Only members with Manage Roles can change roles below their highest role.</p>
                        @endif
                    </form>
                </li>
            @endforeach
        </ul>
    </section>

    <p class="mt-6 text-sm text-fg-subtle">
        <a href="{{ route('channels.edit', [$server, $channel]) }}" class="text-link hover:underline">Back to channel settings</a>
    </p>
</x-layouts.auth>
