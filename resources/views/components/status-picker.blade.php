{{--
    The signed-in user's status (ISSUE-31): online, idle or do not disturb,
    shown while they are connected. A plain form (PUT /settings/status);
    resources/js/presence.js saves it as soon as it changes, hides the
    button and keeps it in step with the user's other tabs.
--}}
@auth
    @php($current = auth()->user()->chosenStatus())
    @php($colors = ['online' => '#23a55a', 'idle' => '#f0b232', 'dnd' => '#f23f43'])
    <form method="POST" action="{{ route('status.update') }}" class="flex items-center gap-2 rounded px-2 py-1" data-status-picker>
        @csrf
        @method('PUT')
        <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $colors[$current] }}" aria-hidden="true" data-status-dot></span>
        <label for="status-picker" class="sr-only">Your status</label>
        <select
            id="status-picker"
            name="status"
            class="min-w-0 flex-1 rounded bg-[#1e1f22] px-2 py-1 text-sm text-[#dbdee1] outline-none focus:ring-2 focus:ring-[#5865f2]"
            data-status-select
        >
            @foreach (\App\Models\User::STATUS_LABELS as $value => $label)
                <option value="{{ $value }}" @selected($value === $current)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded px-2 py-1 text-sm text-[#b5bac1] hover:bg-[#35373c] hover:text-white" data-status-submit>Set</button>
    </form>
@endauth
