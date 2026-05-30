<?php

declare(strict_types=1);

namespace PanicMic\Http;

use PanicMic\Auth\Auth;
use PanicMic\Database\Connection;
use PanicMic\Services\TenantBrandingService;
use PanicMic\Support\Request;
use PanicMic\Support\Response;

final class BrandingController
{
    /** @param array<string,mixed> $tenant */
    public static function show(array $tenant): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        Response::json(['branding' => TenantBrandingService::get(Connection::super(), (int)$tenant['id'])]);
    }

    /** @param array<string,mixed> $tenant */
    public static function update(array $tenant): never
    {
        Auth::requireTenantRole('tenant_admin');
        TenantBrandingService::update(Connection::super(), (int)$tenant['id'], Request::input());
        Response::json(['branding' => TenantBrandingService::get(Connection::super(), (int)$tenant['id'])]);
    }
}
