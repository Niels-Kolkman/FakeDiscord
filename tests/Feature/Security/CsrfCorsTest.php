<?php

/**
 * CSRF, CORS, cookies and browser hardening headers.
 *
 * Laravel skips the CSRF check while running tests, so these swap in a
 * ValidateCsrfToken that always checks, the way it runs in production.
 */

use App\Http\Middleware\SecurityHeaders;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes that skip the CSRF check, reviewed. Laravel itself registers
 * /broadcasting/auth without it: it only signs a socket id for a channel
 * the session may join, a cross-site page cannot read that answer, and
 * the session cookie is SameSite=Lax, so a cross-site POST has no session.
 */
const CSRF_EXEMPT_ROUTES = ['broadcasting/auth'];

function enforceCsrf(Application $app): void
{
    $app->bind(ValidateCsrfToken::class, fn (Application $app) => new class($app, $app->make('encrypter')) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
}

/**
 * A URI for $route with every {parameter} filled in with something its
 * where() pattern accepts.
 */
function sampleUri(Route $route): string
{
    return (string) preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($route): string {
        $pattern = $route->wheres[$m[1]] ?? null;
        foreach (['1', (string) Str::uuid(), 'abc'] as $candidate) {
            if ($pattern === null || preg_match('/^(?:'.$pattern.')$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return '1';
    }, '/'.ltrim($route->uri(), '/'));
}

/**
 * Every route that changes something: POST, PUT, PATCH or DELETE.
 *
 * @return list<array{string, Route}>
 */
function stateChangingRoutes(): array
{
    $routes = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
            $routes[] = [$method, $route];
        }
    }

    return $routes;
}

it('runs every state-changing route through the web group', function () {
    $routes = stateChangingRoutes();
    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $route]) {
        expect(in_array('web', $route->gatherMiddleware(), true))
            ->toBeTrue("{$method} /{$route->uri()} is outside the web group (no CSRF)");
    }
});

it('refuses every state-changing route without a CSRF token', function () {
    enforceCsrf($this->app);
    $user = User::factory()->create();

    foreach (stateChangingRoutes() as [$method, $route]) {
        if (in_array($route->uri(), CSRF_EXEMPT_ROUTES, true)) {
            continue;
        }

        $uri = sampleUri($route);

        // Signed in, so `auth` cannot answer first with a redirect.
        $status = $this->actingAs($user)->call($method, $uri)->getStatusCode();

        expect($status)->toBe(419, "{$method} {$uri} answered {$status} without a CSRF token");
    }
});

it('exempts only the reviewed routes from CSRF', function () {
    $exempt = collect(stateChangingRoutes())
        ->filter(fn (array $entry) => in_array(ValidateCsrfToken::class, $entry[1]->excludedMiddleware(), true)
            || in_array('Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', $entry[1]->excludedMiddleware(), true))
        ->map(fn (array $entry) => $entry[1]->uri())
        ->unique()->values()->all();

    expect($exempt)->toBe(CSRF_EXEMPT_ROUTES);
});

it('does not serve the private disk through Laravel storage routes', function () {
    expect(app('router')->getRoutes()->getByName('storage.local'))->toBeNull()
        ->and(app('router')->getRoutes()->getByName('storage.local.upload'))->toBeNull();
});

it('accepts a request with the session token', function () {
    enforceCsrf($this->app);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['_token' => 'known-token'])
        ->post('/servers', ['_token' => 'known-token', 'name' => 'Token Server'])
        ->assertRedirect();

    expect(Server::query()->where('name', 'Token Server')->exists())->toBeTrue();

    $this->actingAs($user)
        ->withSession(['_token' => 'known-token'])
        ->withHeader('X-CSRF-TOKEN', 'wrong-token')
        ->post('/servers', ['name' => 'Forged Server'])
        ->assertStatus(419);
});

it('sends no CORS headers to other origins', function (string $method) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders([
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'X-CSRF-TOKEN',
        ])
        ->call($method, '/dashboard')
        ->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
})->with(['OPTIONS', 'GET']);

/**
 * reverb.apps.apps.0.allowed_origins as config/reverb.php works it out
 * with these environment variables (null = unset).
 *
 * @param  array<string, string|null>  $env
 * @return list<string>
 */
function reverbOriginsWith(array $env): array
{
    $saved = [];
    foreach ($env as $key => $value) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        } else {
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        return (require config_path('reverb.php'))['apps']['apps'][0]['allowed_origins'];
    } finally {
        foreach ($saved as $key => [$server, $environment, $process]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($environment === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $environment;
            }
            putenv($process === false ? $key : "{$key}={$process}");
        }
    }
}

it('only lets pages of the app itself open a Reverb websocket', function (array $env, array $origins) {
    expect(reverbOriginsWith($env))->toBe($origins);
})->with([
    'production: the APP_URL host only' => [
        ['APP_ENV' => 'production', 'APP_URL' => 'https://chat.example.com', 'REVERB_ALLOWED_ORIGINS' => null],
        ['chat.example.com'],
    ],
    'local: also localhost and 127.0.0.1' => [
        ['APP_ENV' => 'local', 'APP_URL' => 'http://localhost', 'REVERB_ALLOWED_ORIGINS' => null],
        ['localhost', '127.0.0.1'],
    ],
    'an explicit list wins' => [
        ['APP_ENV' => 'production', 'APP_URL' => 'https://chat.example.com', 'REVERB_ALLOWED_ORIGINS' => ' chat.example.com, *.example.org ,'],
        ['chat.example.com', '*.example.org'],
    ],
]);

it('keeps the session cookie away from scripts and cross-site requests', function () {
    $cookie = collect($this->get('/login')->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('sends the hardening headers on pages', function () {
    $response = $this->get('/login')->assertOk();

    foreach (SecurityHeaders::HEADERS as $name => $value) {
        $response->assertHeader($name, $value);
    }
});

it('keeps a stricter header a response already has', function () {
    $response = (new SecurityHeaders)->handle(Request::create('/'), fn () => new Response('', 200, [
        'Content-Security-Policy' => "default-src 'none'; sandbox",
    ]));

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
});
