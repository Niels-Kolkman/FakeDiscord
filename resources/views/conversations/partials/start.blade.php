{{-- "Start of the conversation" header of a DM's message panel (ISSUE-23). @var \App\Models\User|null $other --}}
@if ($other)
    <x-user-avatar :user="$other" :size="80" class="mb-2" />
    <h2 class="text-3xl font-bold text-white">{{ $other->name }}</h2>
    <p class="mb-1 text-lg text-[#dbdee1]">{{ '@'.$other->username }}</p>
    <p class="text-[#b5bac1]">This is the beginning of your direct message history with <strong class="text-white">{{ $other->name }}</strong>.</p>
@else
    <h2 class="text-3xl font-bold text-white">{{ __('Deleted User') }}</h2>
    <p class="text-[#b5bac1]">This is the beginning of your direct message history.</p>
@endif
