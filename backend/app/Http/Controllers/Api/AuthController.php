<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Local accounts on Sanctum SPA cookie sessions (call GET /sanctum/csrf-cookie first).
 * v2 (mobile app) will add bearer-token login; HasApiTokens and the tokens table are already in place.
 */
class AuthController extends Controller
{
    /** Lets the UI decide between the sign-up and sign-in screen on first launch. */
    public function status(): JsonResponse
    {
        return response()->json([
            'has_users' => User::query()->exists(),
            'registration_open' => $this->registrationOpen(),
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        abort_unless($this->registrationOpen(), 403, 'Registration is closed.');

        $user = User::query()->create($request->validated());

        // Sign the browser in right away. A request from outside the SPA origin has no session to sign into.
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): UserResource
    {
        abort_unless($request->hasSession(), 400, 'Sign-in is only available from the web UI (SANCTUM_STATEFUL_DOMAINS).');
        $this->ensureNotRateLimited($request);

        if (! Auth::guard('web')->attempt($request->credentials(), $request->boolean('remember'))) {
            RateLimiter::hit($request->throttleKey());

            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }

        RateLimiter::clear($request->throttleKey());
        $request->session()->regenerate();

        return UserResource::make(Auth::guard('web')->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        // Explicit 200: JsonResource would answer 201 for a model created earlier in the same process.
        return UserResource::make($request->user())->response()->setStatusCode(200);
    }

    private function registrationOpen(): bool
    {
        return match (config('smarthome.registration')) {
            'closed' => false,
            'first_user' => ! User::query()->exists(),
            default => true,
        };
    }

    private function ensureNotRateLimited(LoginRequest $request): void
    {
        if (! RateLimiter::tooManyAttempts($request->throttleKey(), config('smarthome.rate_limits.login'))) {
            return;
        }

        throw ValidationException::withMessages([
            'login' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($request->throttleKey()), 'minutes' => 1]),
        ])->status(429);
    }
}
