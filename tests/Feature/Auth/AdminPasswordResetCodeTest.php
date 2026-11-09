<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Auth\AdminPasswordResetController;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPasswordResetCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_starts_at_the_email_step(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/ForgotPassword')
                ->where('step', 'email')
                ->where('email', null));
    }

    public function test_admin_receives_a_reset_code_by_email(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['email' => 'admin@safesakay.test']);

        $this->post(route('password.code.send'), ['email' => 'ADMIN@safesakay.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status');

        Notification::assertSentTo($admin, PasswordResetCodeNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'admin@safesakay.test']);

        $this->get(route('password.request'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('step', 'code')
                ->where('email', 'admin@safesakay.test')
                ->where('resetCode', null));
    }

    public function test_reset_code_is_shown_when_email_cannot_be_sent(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);

        User::factory()->create(['email' => 'admin@safesakay.test']);

        $this->post(route('password.code.send'), ['email' => 'admin@safesakay.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('reset_code');

        $code = session('reset_code');

        $this->get(route('password.request'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('step', 'code')
                ->where('resetCode', $code));
    }

    public function test_unknown_email_is_rejected(): void
    {
        Notification::fake();

        $this->post(route('password.code.send'), ['email' => 'nobody@safesakay.test'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_operator_is_sent_to_the_mobile_app(): void
    {
        Notification::fake();

        User::factory()->create([
            'email' => 'operator@safesakay.test',
            'role' => UserRole::Operator,
        ]);

        $this->post(route('password.code.send'), ['email' => 'operator@safesakay.test'])
            ->assertSessionHasErrors([
                'email' => 'Operator accounts reset their password in the SafeSakay mobile app.',
            ]);

        Notification::assertNothingSent();
    }

    public function test_admin_can_reset_password_with_the_emailed_code(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['email' => 'admin@safesakay.test']);

        $this->post(route('password.code.send'), ['email' => $admin->email]);

        $code = null;
        Notification::assertSentTo(
            $admin,
            PasswordResetCodeNotification::class,
            function (PasswordResetCodeNotification $notification) use (&$code): bool {
                $code = $notification->code;

                return true;
            }
        );

        $this->post(route('password.code.verify'), ['code' => $code])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('password.request'));

        $this->get(route('password.request'))
            ->assertInertia(fn (Assert $page) => $page->where('step', 'password'));

        $this->post(route('password.code.reset'), [
            'password' => 'SafeSakay@1',
            'password_confirmation' => 'SafeSakay@1',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('SafeSakay@1', $admin->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);
        $this->assertNull(session(AdminPasswordResetController::SESSION_KEY));
    }

    public function test_wrong_code_is_rejected(): void
    {
        Notification::fake();

        $admin = User::factory()->create();

        $this->post(route('password.code.send'), ['email' => $admin->email]);

        $this->post(route('password.code.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->get(route('password.request'))
            ->assertInertia(fn (Assert $page) => $page->where('step', 'code'));
    }

    public function test_password_cannot_be_reset_before_the_code_is_verified(): void
    {
        Notification::fake();

        $admin = User::factory()->create();

        $this->post(route('password.code.send'), ['email' => $admin->email]);

        $this->post(route('password.code.reset'), [
            'password' => 'SafeSakay@1',
            'password_confirmation' => 'SafeSakay@1',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }
}
