<?php

namespace Tests;

use App\Support\ServerCache;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Start the next request with nothing in App\Support\ServerCache
     * (ISSUE-42). Tests that compare the queries of two requests call it
     * before each, so neither gets the other's cached permissions.
     */
    protected function coldServerCache(): void
    {
        $this->app->make(ServerCache::class)->flush();
    }
}
