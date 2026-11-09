<?php

namespace Tests\Unit;

use App\Support\XenditRedirectUrls;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class XenditRedirectUrlsTest extends TestCase
{
    public function test_local_http_lan_redirects_are_allowed_when_flag_set(): void
    {
        Config::set('app.url', 'http://localhost:8000');
        Config::set('services.xendit.success_redirect_url', 'http://192.168.254.126:8000/payment/xendit/success');
        Config::set('services.xendit.failure_redirect_url', 'http://192.168.254.126:8000/payment/xendit/cancel');
        Config::set('services.xendit.allow_http_redirects', true);
        Config::set('services.xendit.secret_key', 'test-key');

        $this->assertNull(XenditRedirectUrls::configurationMessage());
        $this->assertTrue(XenditRedirectUrls::configured());
    }

    public function test_localhost_redirects_blocked_for_phone_with_helpful_message(): void
    {
        Config::set('app.url', 'http://localhost:8000');
        Config::set('services.xendit.success_redirect_url', '');
        Config::set('services.xendit.failure_redirect_url', '');
        Config::set('services.xendit.allow_http_redirects', true);
        Config::set('services.xendit.secret_key', 'test-key');

        $message = XenditRedirectUrls::configurationMessage();

        $this->assertNotNull($message);
        $this->assertStringContainsString('LAN-IP', $message);
        $this->assertFalse(XenditRedirectUrls::configured());
    }

    public function test_production_requires_https_without_allow_http_flag(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        Config::set('app.url', 'http://example.test');
        Config::set('services.xendit.success_redirect_url', 'http://example.test/payment/xendit/success');
        Config::set('services.xendit.failure_redirect_url', 'http://example.test/payment/xendit/cancel');
        Config::set('services.xendit.allow_http_redirects', true);
        Config::set('services.xendit.secret_key', 'test-key');

        $this->assertFalse(XenditRedirectUrls::isValid('http://example.test/payment/xendit/success'));
    }
}
