<?php

namespace Tests\Feature;

use Tests\TestCase;

class XenditAppReturnRedirectTest extends TestCase
{
    public function test_success_return_redirects_straight_to_the_app(): void
    {
        $this->get('/payment/xendit/success?'.http_build_query([
            'booking_id' => 80,
            'payment_type' => 'downpayment',
            'app_return' => 'exp://192.168.1.5:8081/--/xendit-return',
        ]))->assertRedirect('exp://192.168.1.5:8081/--/xendit-return?id=80&plan=downpayment&verify=1');
    }

    public function test_cancel_return_tells_the_app_it_was_cancelled(): void
    {
        $this->get('/payment/xendit/cancel?'.http_build_query([
            'booking_id' => 80,
            'payment_type' => 'full_rental',
            'app_return' => 'safesakay://xendit-return',
        ]))->assertRedirect('safesakay://xendit-return?id=80&plan=full_rental&cancelled=1');
    }

    public function test_non_app_return_targets_are_ignored(): void
    {
        $this->get('/payment/xendit/success?'.http_build_query([
            'booking_id' => 80,
            'payment_type' => 'downpayment',
            'app_return' => 'https://evil.example/steal',
        ]))->assertOk()->assertSee('Payment successful');
    }
}
