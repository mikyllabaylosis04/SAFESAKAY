<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordResetCodeService
{
    public const EXPIRES_IN_MINUTES = 15;

    /**
     * Store a reset code and email it.
     *
     * @return string|null The code when email delivery failed, so the requester can still continue.
     */
    public function send(User $user): ?string
    {
        $email = Str::lower($user->email);
        $code = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($code),
                'created_at' => now(),
            ],
        );

        try {
            $user->notify(new PasswordResetCodeNotification($code));
        } catch (Throwable $exception) {
            report($exception);

            return $code;
        }

        return null;
    }

    public function verify(string $email, string $code): User
    {
        $email = Str::lower($email);
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $record || ! Hash::check($code, $record->token)) {
            throw ValidationException::withMessages([
                'code' => ['The reset code is invalid.'],
            ]);
        }

        if (Carbon::parse($record->created_at)->lt(now()->subMinutes(self::EXPIRES_IN_MINUTES))) {
            throw ValidationException::withMessages([
                'code' => ['The reset code has expired. Request a new one.'],
            ]);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['The reset code is invalid.'],
            ]);
        }

        return $user;
    }

    public function forget(string $email): void
    {
        DB::table('password_reset_tokens')->where('email', Str::lower($email))->delete();
    }
}
