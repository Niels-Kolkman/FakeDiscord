{{--
    The signed-in user, for resources/js/friends.js (live friend requests,
    ISSUE-22) and resources/js/presence.js (ISSUE-31: the servers whose
    presence channel every page joins, and the status the user picked).
    Nothing for guests.
--}}
@auth
    <meta name="user-id" content="{{ auth()->id() }}">
    <meta name="friends-url" content="{{ route('friends.index') }}">
    <meta name="presence-servers" content="{{ auth()->user()->servers()->pluck('servers.id')->implode(',') }}">
    <meta name="user-status" content="{{ auth()->user()->chosenStatus() }}">
    <meta name="status-url" content="{{ route('status.update') }}">
@endauth
