{{-- "Start of the conversation" header of a DM's message panel. @var \App\Models\User|null $other --}}
@if ($other)
    <x-user-avatar :user="$other" :size="80" class="mb-2" />
    <h2 class="text-3xl font-bold text-fg-strong">{{ $other->name }}</h2>
    <p class="mb-1 text-lg text-fg">{{ '@'.$other->username }}</p>
    <p class="text-fg-muted">This is the beginning of your direct message history with <strong class="text-fg-strong">{{ $other->name }}</strong>.</p>
@else
    <h2 class="text-3xl font-bold text-fg-strong">{{ __('Deleted User') }}</h2>
    <p class="text-fg-muted">This is the beginning of your direct message history.</p>
@endif
