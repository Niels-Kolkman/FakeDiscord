{{-- The signed-in user, for resources/js/friends.js (live friend requests, ISSUE-22). Nothing for guests. --}}
@auth
    <meta name="user-id" content="{{ auth()->id() }}">
    <meta name="friends-url" content="{{ route('friends.index') }}">
@endauth
