<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordResetCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AdminPasswordResetController extends Controller
{
    public const SESSION_KEY = 'admin_password_reset';

    public function __construct(private PasswordResetCodeService $codes) {}

    /**
     * @return array{email: string, verified: bool, code?: string, sent_at: int}|null
     */
    public static function state(Request $request): ?array
    {
        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state)) {
            return null;
        }

        $expiresAt = ($state['sent_at'] ?? 0) + PasswordResetCodeService::EXPIRES_IN_MINUTES * 60;

        if (now()->timestamp > $expiresAt) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $state;
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = Str::lower(trim($data['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => 'No admin account uses this email.',
            ]);
        }

        if (! $user->role->canAccessAdminWeb()) {
            throw ValidationException::withMessages([
                'email' => $user->role->canonical() === UserRole::Operator
                    ? 'Operator accounts reset their password in the SafeSakay mobile app.'
                    : 'Customer accounts reset their password in the SafeSakay mobile app.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account is inactive. Ask an administrator to restore access.',
            ]);
        }

        $fallbackCode = $this->codes->send($user);

        $request->session()->put(self::SESSION_KEY, [
            'email' => $email,
            'verified' => false,
            'sent_at' => now()->timestamp,
        ]);

        if ($fallbackCode !== null) {
            return to_route('password.request')
                ->with('status', 'Email could not be sent from this server. Use the code shown below. It expires in 15 minutes.')
                ->with('reset_code', $fallbackCode);
        }

        return to_route('password.request')
            ->with('status', "We sent a 6-digit code to {$user->email}. Check your inbox and Spam folder.");
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $state = self::state($request);

        if (! $state) {
            return $this->restart('Your code expired. Enter your email to get a new one.');
        }

        $this->codes->verify($state['email'], $data['code']);

        $request->session()->put(self::SESSION_KEY, [
            ...$state,
            'verified' => true,
            'code' => $data['code'],
        ]);

        return to_route('password.request')->with('status', 'Code verified. Set your new password.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $state = self::state($request);

        if (! $state || ! $state['verified'] || empty($state['code'])) {
            return $this->restart('Verify your code first.');
        }

        $data = $request->validate([
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        try {
            $user = $this->codes->verify($state['email'], $state['code']);
        } catch (ValidationException) {
            return $this->restart('Your code expired. Enter your email to get a new one.');
        }

        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        $user->tokens()->delete();
        $this->codes->forget($state['email']);
        $request->session()->forget(self::SESSION_KEY);

        return to_route('login')->with('status', 'Password reset. Log in with your new password.');
    }

    public function startOver(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return to_route('password.request');
    }

    private function restart(string $message): RedirectResponse
    {
        request()->session()->forget(self::SESSION_KEY);

        return to_route('password.request')->withErrors(['email' => $message]);
    }
}
