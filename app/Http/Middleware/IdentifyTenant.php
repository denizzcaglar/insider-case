<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class IdentifyTenant
{
    public const COOKIE_NAME = 'insider_tenant';
    public const COOKIE_TTL_MINUTES = 60 * 24 * 7;

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningUnitTests()) {
            return $next($request);
        }

        $tenantId = $this->resolveTenantId($request);
        app()->instance(TenantContext::class, new TenantContext($tenantId));

        $response = $next($request);

        // Set directly: Laravel 11 api group has no queued-cookies middleware.
        $response->headers->setCookie(new Cookie(
            name: self::COOKIE_NAME,
            value: $tenantId,
            expire: time() + self::COOKIE_TTL_MINUTES * 60,
            path: '/',
            domain: null,
            secure: $request->secure(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    private function resolveTenantId(Request $request): string
    {
        $existing = $request->cookie(self::COOKIE_NAME);
        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }

        return (string) Str::uuid();
    }
}
