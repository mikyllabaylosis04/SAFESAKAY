<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Controllers\Auth\AdminPasswordResetController;
use App\Http\Responses\LoginResponse;
use App\Models\Organization;
use App\Models\User;
use App\Services\PasswordResetCodeService;
use App\Support\PlatformSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::query()->where('email', $request->email)->first();

            if (! $user || ! Hash::check((string) $request->password, $user->password)) {
                return null;
            }

            if ($user->isSuperAdmin()) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Super Admin must sign in at /superadmin.',
                ]);
            }

            if ($user->organizationBlocksLogin()) {
                throw ValidationException::withMessages([
                    Fortify::username() => $user->organizationAccessDenialMessage(),
                ]);
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'This account is inactive. Ask an administrator to restore access.',
                ]);
            }

            if ($message = $user->operatorSuspensionDenialMessage()) {
                throw ValidationException::withMessages([
                    Fortify::username() => $message,
                ]);
            }

            if (! $user->role->canAccessAdminWeb()) {
                throw ValidationException::withMessages([
                    Fortify::username() => $user->role->adminWebDenialMessage(),
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('adminlogin', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'restore_request_reasons' => Organization::reactivationReasons(),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(function (Request $request) {
            $state = AdminPasswordResetController::state($request);

            return Inertia::render('auth/ForgotPassword', [
                'status' => $request->session()->get('status'),
                'resetCode' => $request->session()->get('reset_code'),
                'email' => $state['email'] ?? null,
                'step' => match (true) {
                    $state === null => 'email',
                    ! $state['verified'] => 'code',
                    default => 'password',
                },
                'codeExpiresInMinutes' => PasswordResetCodeService::EXPIRES_IN_MINUTES,
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ]);
        });

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $login = (string) ($request->input(Fortify::username()) ?? $request->input('username', ''));
            $throttleKey = Str::transliterate(Str::lower($login).'|'.$request->ip());

            return Limit::perMinute(PlatformSettings::maxLoginAttempts())->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
