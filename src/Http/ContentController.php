<?php

declare(strict_types=1);

namespace NextUp\Http;

use NextUp\Auth\Auth;
use NextUp\Services\ContentService;
use NextUp\Support\Response;
use NextUp\Support\Url;

final class ContentController
{
    /** @param array<string,mixed> $tenant */
    public static function index(array $tenant): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        Response::json(['files' => self::contentFiles($tenant)]);
    }

    /** @param array<string,mixed> $tenant */
    public static function upload(array $tenant): never
    {
        Auth::requireTenantRole('kj', 'tenant_admin');
        if (empty($_FILES['content_file']) || !is_array($_FILES['content_file'])) {
            Response::json(['error' => 'No file uploaded'], 400);
        }
        $file = ContentService::storeUpload((string)$tenant['slug'], $_FILES['content_file']);
        $file['url'] = Url::path($file['url']);
        Response::json(['file' => $file, 'files' => self::contentFiles($tenant)]);
    }

    /**
     * @param array<string,mixed> $tenant
     * @return list<array<string,mixed>>
     */
    private static function contentFiles(array $tenant): array
    {
        return array_map(static function (array $file): array {
            $file['url'] = Url::path($file['url']);
            return $file;
        }, ContentService::list((string)$tenant['slug']));
    }
}
