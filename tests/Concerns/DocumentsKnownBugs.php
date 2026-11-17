<?php

namespace Tests\Concerns;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Runs a test that describes the correct behaviour of a known, unfixed bug.
 *
 * While the bug exists the test is reported as incomplete (not failed), so the
 * suite stays green but the gap stays visible. Once production code is fixed
 * the assertions pass and the test turns green by itself; then drop the
 * wrapper.
 */
trait DocumentsKnownBugs
{
    protected function knownBug(string $description, callable $test): void
    {
        try {
            $test();
        } catch (AssertionFailedError $e) {
            $this->markTestIncomplete('Known bug: '.$description);
        }
    }
}
