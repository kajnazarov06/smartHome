<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every limit in config/smarthome.php `rate_limits` is wired to its route and read from config. */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public static function throttledRoutes(): array
    {
        return [
            'register' => ['register', '/api/auth/register', false],
            'pairing start' => ['pairing', '/api/pairing/start', true],
            'pairing confirm' => ['pairing', '/api/pairing/confirm', true],
            'discovery scan' => ['discovery_scan', '/api/discovery/scan', true],
            'discovery probe' => ['discovery_probe', '/api/discovery/probe', true],
        ];
    }

    #[DataProvider('throttledRoutes')]
    public function test_named_limiter_uses_the_configured_limit(string $limit, string $uri, bool $signedIn): void
    {
        Queue::fake();
        Http::fake(['*' => Http::failedConnection()]);
        config(["smarthome.rate_limits.{$limit}" => 1]);
        if ($signedIn) {
            $this->actingAs(User::factory()->create());
        }

        // Throttling runs before validation, so any first response counts; the second one is refused.
        $this->assertNotSame(429, $this->postJson($uri)->status());
        $this->postJson($uri)->assertTooManyRequests();
    }

    public function test_login_uses_the_configured_limit(): void
    {
        config(['smarthome.rate_limits.login' => 1]);
        User::factory()->create(['username' => 'aziz', 'password' => 'secret-password']);
        $spa = ['Origin' => 'http://localhost', 'Referer' => 'http://localhost/'];

        $this->withHeaders($spa)->postJson('/api/auth/login', ['login' => 'aziz', 'password' => 'nope'])->assertUnprocessable();
        $this->withHeaders($spa)->postJson('/api/auth/login', ['login' => 'aziz', 'password' => 'secret-password'])->assertStatus(429);
    }

    public function test_a_missing_limit_falls_back_instead_of_failing(): void
    {
        config(['smarthome.rate_limits' => []]);

        $this->postJson('/api/auth/register')->assertUnprocessable();
    }

    public function test_an_empty_or_invalid_env_value_keeps_the_default(): void
    {
        foreach (['7' => 7, '' => 10, 'abc' => 10, '0' => 10, '-3' => 10] as $value => $expected) {
            $value = (string) $value;
            putenv("RATE_LIMIT_PAIRING={$value}");
            $_ENV['RATE_LIMIT_PAIRING'] = $_SERVER['RATE_LIMIT_PAIRING'] = $value;

            $this->assertSame($expected, (require config_path('smarthome.php'))['rate_limits']['pairing'], "value '{$value}'");
        }

        putenv('RATE_LIMIT_PAIRING');
        unset($_ENV['RATE_LIMIT_PAIRING'], $_SERVER['RATE_LIMIT_PAIRING']);
    }
}
