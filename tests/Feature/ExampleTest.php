<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_the_root_route_renders_the_welcome_page(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertViewIs('welcome')
            ->assertSee('<title>'.config('app.name').'</title>', false);
    }
}
