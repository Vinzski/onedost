<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Wrong passwords allowed from one place a minute, and for one email an hour, before the form makes you wait. */
    private const PER_MINUTE = 5;

    private const PER_HOUR = 20;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['boolean'],
        ];
    }

    /**
     * Sign in, or say why not. A wrong email and a wrong password get the
     * same answer, so the form never tells a stranger which emails exist.
     */
    public function authenticate(): void
    {
        $this->ensureNotLockedOut();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->minuteKey(), 60);
            RateLimiter::hit($this->hourKey(), 3600);

            throw ValidationException::withMessages(['email' => 'That email and password don\'t match.']);
        }

        RateLimiter::clear($this->minuteKey());
        RateLimiter::clear($this->hourKey());
    }

    private function ensureNotLockedOut(): void
    {
        foreach ([[$this->minuteKey(), self::PER_MINUTE], [$this->hourKey(), self::PER_HOUR]] as [$key, $tries]) {
            if (RateLimiter::tooManyAttempts($key, $tries)) {
                event(new Lockout($this));
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

                throw ValidationException::withMessages([
                    'email' => "Too many tries. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
                ]);
            }
        }
    }

    /** Per email and IP: slows down guessing from one place. */
    private function minuteKey(): string
    {
        return 'login:'.$this->emailKey().'|'.$this->ip();
    }

    /** Per email from anywhere: a guesser can't get round it by changing address. */
    private function hourKey(): string
    {
        return 'login:'.$this->emailKey();
    }

    private function emailKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->string('email')));
    }
}
