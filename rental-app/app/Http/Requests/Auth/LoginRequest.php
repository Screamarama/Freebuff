<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // A deactivated account or tenant keeps its password, so the credential
        // check above succeeds — undo the session before it can reach anything.
        if (! $this->user()->is_active) {
            $this->rejectLogin('This account has been deactivated. Contact your administrator.');
        }

        if (! $this->tenantAllowsLogin($this->user())) {
            $this->rejectLogin('This business account has been deactivated. Contact your provider.');
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Super admins sit above tenants, so a suspended tenant must not lock the
     * platform owner out. Users with no tenant (e.g. legacy accounts) are only
     * subject to their own is_active flag.
     */
    private function tenantAllowsLogin(User $user): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        return $user->business === null || $user->business->is_active;
    }

    /**
     * Undo the authenticated session and surface the reason on the email field.
     *
     * @throws ValidationException
     */
    private function rejectLogin(string $message): never
    {
        Auth::guard('web')->logout();
        $this->session()->invalidate();
        $this->session()->regenerateToken();

        throw ValidationException::withMessages([
            'email' => $message,
        ]);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
