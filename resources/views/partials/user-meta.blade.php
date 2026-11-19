{{-- The signed-in user, for resources/js/friends.js (live friend requests, ISSUE-22) and resources/js/unread.js (mentions, ISSUE-32). Nothing for guests. --}}
@auth
    <meta name="user-id" content="{{ auth()->id() }}">
    <meta name="username" content="{{ auth()->user()->username }}">
    <meta name="friends-url" content="{{ route('friends.index') }}">
@endauth
