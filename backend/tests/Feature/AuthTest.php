<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** Sanctum treats requests from this origin as the SPA (cookie session). */
    private array $spa = ['Origin' => 'http://localhost', 'Referer' => 'http://localhost/'];

    public function test_status_reports_first_launch(): void
    {
        $this->getJson('/api/auth/status')->assertOk()->assertJson(['has_users' => false, 'registration_open' => true]);

        User::factory()->create();

        $this->getJson('/api/auth/status')->assertJson(['has_users' => true]);
    }

    public function test_user_can_register_with_email_and_is_signed_in(): void
    {
        $this->withHeaders($this->spa)->postJson('/api/auth/register', [
            'email' => 'me@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertCreated()->assertJsonPath('data.email', 'me@example.com');

        $this->withHeaders($this->spa)->getJson('/api/user')->assertOk()->assertJsonPath('data.email', 'me@example.com');
    }

    public function test_register_requires_username_or_email(): void
    {
        $this->postJson('/api/auth/register', ['password' => 'secret-password', 'password_confirmation' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'email']);
    }

    public function test_first_user_mode_closes_registration_after_first_account(): void
    {
        config(['smarthome.registration' => 'first_user']);
        User::factory()->create();

        $this->postJson('/api/auth/register', [
            'username' => 'second', 'password' => 'secret-password', 'password_confirmation' => 'secret-password',
        ])->assertForbidden();
    }

    public function test_login_with_username_or_email(): void
    {
        User::factory()->create(['username' => 'aziz', 'email' => 'aziz@example.com', 'password' => 'secret-password']);

        $this->withHeaders($this->spa)->postJson('/api/auth/login', ['login' => 'aziz', 'password' => 'secret-password'])
            ->assertOk()->assertJsonPath('data.username', 'aziz');

        $this->withHeaders($this->spa)->postJson('/api/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->spa)->postJson('/api/auth/login', ['login' => 'aziz@example.com', 'password' => 'secret-password'])
            ->assertOk();
    }

    public function test_wrong_password_fails_and_is_throttled(): void
    {
        User::factory()->create(['username' => 'aziz', 'password' => 'secret-password']);

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($this->spa)->postJson('/api/auth/login', ['login' => 'aziz', 'password' => 'nope'])->assertUnprocessable();
        }

        $this->withHeaders($this->spa)->postJson('/api/auth/login', ['login' => 'aziz', 'password' => 'secret-password'])->assertStatus(429);
    }

    public function test_sign_in_requires_the_web_ui_origin(): void
    {
        User::factory()->create(['username' => 'script', 'password' => 'secret-password']);

        $this->postJson('/api/auth/login', ['login' => 'script', 'password' => 'secret-password'])->assertStatus(400);
        $this->postJson('/api/auth/tokens', ['login' => 'script', 'password' => 'secret-password'])->assertNotFound();
    }

    public function test_unauthenticated_calls_return_401(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/devices')->assertUnauthorized();
        $this->postJson('/api/pairing/start', ['host' => '192.168.1.50'])->assertUnauthorized();
    }
}
