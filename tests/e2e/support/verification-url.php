<?php

/*
 * Prints the email verification link for a user of the browser tests:
 * `php tests/e2e/support/verification-url.php {email}`.
 *
 * The link comes from the same VerifyEmail notification the app sends, so
 * the browser follows exactly the URL that would be in the email; this only
 * saves digging it out of the mail log. Run with the environment from
 * tests/e2e/support/environment.js (APP_URL and the e2e database).
 */

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$email = $argv[1] ?? null;
if (! is_string($email) || $email === '') {
    fwrite(STDERR, "Usage: php tests/e2e/support/verification-url.php {email}\n");
    exit(1);
}

$user = User::query()->where('email', $email)->first();
if ($user === null) {
    fwrite(STDERR, "No user with the email address {$email}.\n");
    exit(1);
}

echo (new VerifyEmail)->toMail($user)->actionUrl;
