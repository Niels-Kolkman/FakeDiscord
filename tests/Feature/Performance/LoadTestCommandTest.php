<?php

/**
 * ISSUE-42 acceptance: no "database is locked" under a simple multi-user
 * load test. `php artisan app:load-test` sends from several processes at
 * once next to one queue worker, on a throwaway SQLite file with the app's
 * settings. A small run here; run a bigger one on the Pi itself.
 */
it('sends from several processes at once without lock errors', function () {
    $this->artisan('app:load-test', ['--users' => 4, '--messages' => 8])
        ->expectsOutputToContain('No lock errors.')
        ->assertSuccessful();
});
