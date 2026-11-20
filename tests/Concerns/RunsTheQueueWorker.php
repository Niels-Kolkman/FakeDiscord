<?php

namespace Tests\Concerns;

/**
 * Broadcasts are queued (ISSUE-42). Tests that set
 * queue.default=database can check that a request only queued its
 * broadcasts, then let the worker send them.
 */
trait RunsTheQueueWorker
{
    /**
     * Run `queue:work` on the database queue until it is empty, like the
     * worker process would.
     */
    protected function runQueueWorker(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
            '--sleep' => 0,
            // The test process itself is past the default 128 MB, which
            // would make the worker stop (exit code 12) after one job.
            '--memory' => 4096,
        ])->assertSuccessful();
    }
}
