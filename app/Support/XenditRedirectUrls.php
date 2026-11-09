<?php

namespace App\Support;

class XenditRedirectUrls
{
    public static function success(): string
    {
        $explicit = trim((string) config('services.xendit.success_redirect_url', ''));

        if ($explicit !== '') {
            return $explicit;
        }

        return rtrim((string) config('app.url'), '/').'/payment/xendit/success';
    }

    public static function failure(): string
    {
        $explicit = trim((string) config('services.xendit.failure_redirect_url', ''));

        if ($explicit !== '') {
            return $explicit;
        }

        return rtrim((string) config('app.url'), '/').'/payment/xendit/cancel';
    }

    /**
     * Mobile deep link the return page may send the passenger back to.
     * Only app schemes are accepted so the return page cannot be used as an open redirect.
     */
    public static function appReturn(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || strlen($url) > 500 || preg_match('/[\s<>"\']/', $url)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['exp', 'exps', 'safesakay'], true) ? $url : null;
    }

    public static function isValid(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http'
            && (bool) config('services.xendit.allow_http_redirects', false)
            && app()->environment(['local', 'testing']);
    }

    public static function configured(): bool
    {
        if (! self::isValid(self::success()) || ! self::isValid(self::failure())) {
            return false;
        }

        if (self::usesHttpsForXenditApi()) {
            return true;
        }

        if (! self::allowsLocalHttpRedirects()) {
            return false;
        }

        return ! self::redirectHostIsLoopback(self::success())
            && ! self::redirectHostIsLoopback(self::failure());
    }

    public static function usesHttpsForXenditApi(): bool
    {
        return self::isHttps(self::success()) && self::isHttps(self::failure());
    }

    public static function allowsLocalHttpRedirects(): bool
    {
        return (bool) config('services.xendit.allow_http_redirects', false)
            && app()->environment(['local', 'testing']);
    }

    private static function isHttps(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /**
     * Human-readable reason when checkout cannot start (null = OK).
     */
    public static function configurationMessage(): ?string
    {
        if (! self::isValid(self::success()) || ! self::isValid(self::failure())) {
            $appUrl = rtrim((string) config('app.url'), '/');

            if (str_starts_with(strtolower($appUrl), 'http://') && ! (bool) config('services.xendit.allow_http_redirects', false)) {
                return 'Set HTTPS redirect URLs (e.g. ngrok) in XENDIT_SUCCESS_REDIRECT_URL and XENDIT_FAILURE_REDIRECT_URL, or set XENDIT_ALLOW_HTTP_REDIRECTS=true for local HTTP APP_URL.';
            }

            return 'Xendit checkout redirect URLs are not configured. Set APP_URL or XENDIT_SUCCESS_REDIRECT_URL / XENDIT_FAILURE_REDIRECT_URL.';
        }

        if (! self::usesHttpsForXenditApi()) {
            if (self::allowsLocalHttpRedirects()) {
                if (
                    self::redirectHostIsLoopback(self::success())
                    || self::redirectHostIsLoopback(self::failure())
                ) {
                    return 'For phone testing, set XENDIT_SUCCESS_REDIRECT_URL and XENDIT_FAILURE_REDIRECT_URL to http://YOUR-PC-LAN-IP:8000/payment/xendit/success and .../cancel (not localhost). XENDIT_ALLOW_HTTP_REDIRECTS is already true.';
                }

                if (trim((string) config('services.xendit.secret_key', '')) === '') {
                    return 'Xendit secret key is not configured on the server (XENDIT_SECRET_KEY).';
                }

                return null;
            }

            return 'Xendit requires HTTPS return URLs. Set XENDIT_SUCCESS_REDIRECT_URL and XENDIT_FAILURE_REDIRECT_URL (ngrok HTTPS → /payment/xendit/success and /cancel on this server), or enable XENDIT_ALLOW_HTTP_REDIRECTS=true with LAN http URLs in local only.';
        }

        if (trim((string) config('services.xendit.secret_key', '')) === '') {
            return 'Xendit secret key is not configured on the server (XENDIT_SECRET_KEY).';
        }

        return null;
    }

    private static function redirectHostIsLoopback(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
