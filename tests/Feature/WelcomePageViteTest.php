<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * ISSUE-1: with `npm run dev` running, the welcome page loads its CSS/JS
 * from the Vite dev server.
 */
class WelcomePageViteTest extends TestCase
{
    private string $hotFile;

    protected function setUp(): void
    {
        parent::setUp();

        // `npm run dev` writes the dev server URL to a "hot" file. We fake one.
        $this->hotFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fakediscord-hot-'.uniqid('', true);
        file_put_contents($this->hotFile, 'http://localhost:5173');

        Vite::useHotFile($this->hotFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->hotFile);

        parent::tearDown();
    }

    public function test_welcome_page_loads_assets_from_the_vite_dev_server(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('http://localhost:5173/@vite/client', false)
            ->assertSee('http://localhost:5173/resources/css/app.css', false)
            ->assertSee('http://localhost:5173/resources/js/app.js', false);
    }

    public function test_welcome_page_uses_the_fakediscord_app_name_as_title(): void
    {
        config()->set('app.name', 'FakeDiscord');

        $this->get('/')->assertSee('<title>FakeDiscord</title>', false);
    }
}
