<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesPermissions;
use Tests\Concerns\RunsTheQueueWorker;

/**
 * The base of the function-style Pest feature tests (ISSUE-39, bound in
 * tests/Pest.php): a fresh database, the permission helpers, the queue
 * worker (ISSUE-42) and no Vite manifest needed to render pages.
 *
 * tests/stubs/pest.stub tells PHPStan that $this inside it(), test() and
 * beforeEach() closures is this class.
 */
abstract class FeatureTestCase extends TestCase
{
    use GivesPermissions, RefreshDatabase, RunsTheQueueWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
