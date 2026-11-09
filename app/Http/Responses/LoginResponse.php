<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    public function toResponse($request): Response
    {
        $intended = $request->session()->pull('url.intended');
        $home = $request->user()?->homeRouteName() ?? 'dashboard';
        $target = route($home);

        if ($request->user()?->isSuperAdmin() && $this->isPlatformUrl($intended)) {
            $target = $intended;
        }

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false, 'redirect' => $target])
            : redirect()->to($target);
    }

    private function isPlatformUrl(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return str_starts_with($path, '/platform') || str_starts_with($path, '/super-admin');
    }
}
