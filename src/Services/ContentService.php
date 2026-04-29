<?php

declare(strict_types=1);

namespace NextUp\Services;

final class ContentService
{
    /** @var array<string,string> */
    private const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'pdf' => 'application/pdf',
    ];

    public static function tenantDirectory(string $accountName): string
    {
        $safeName = self::safeAccountName($accountName);
        return dirname(__DIR__, 2) . '/content/' . $safeName;
    }

    public static function ensureTenantDirectory(string $accountName): string
    {
        $dir = self::tenantDirectory($accountName);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create tenant content directory');
        }
        return $dir;
    }

    /** @return list<array<string,mixed>> */
    public static function list(string $accountName): array
    {
        $dir = self::ensureTenantDirectory($accountName);
        $files = [];
        foreach (new \DirectoryIterator($dir) as $file) {
            if (!$file->isFile() || str_starts_with($file->getFilename(), '.')) {
                continue;
            }
            $files[] = [
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'modified' => date(DATE_ATOM, $file->getMTime()),
                'url' => '/files/' . rawurlencode($file->getFilename()),
            ];
        }
        usort($files, static fn (array $a, array $b): int => strcmp($b['modified'], $a['modified']));
        return $files;
    }

    /** @param array<string,mixed> $upload */
    public static function storeUpload(string $accountName, array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Upload failed');
        }
        $original = basename((string)$upload['name']);
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::MIME_TYPES[$extension])) {
            throw new \InvalidArgumentException('Unsupported file type');
        }
        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME)) ?: 'file';
        $filename = trim($safeBase, '.-') . '-' . date('YmdHis') . '.' . $extension;
        $dir = self::ensureTenantDirectory($accountName);
        $destination = $dir . '/' . $filename;
        if (!move_uploaded_file((string)$upload['tmp_name'], $destination)) {
            throw new \RuntimeException('Unable to save uploaded file');
        }
        chmod($destination, 0664);
        return ['name' => $filename, 'url' => '/files/' . rawurlencode($filename), 'size' => filesize($destination)];
    }

    public static function serve(string $accountName, string $path): never
    {
        $dir = realpath(self::ensureTenantDirectory($accountName));
        $relative = ltrim(rawurldecode($path), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            http_response_code(404);
            exit;
        }
        $file = realpath($dir . '/' . $relative);
        if (!$file || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR) || !is_file($file)) {
            http_response_code(404);
            exit;
        }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . (self::MIME_TYPES[$extension] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }

    public static function safeAccountName(string $accountName): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower($accountName));
        return trim($safe ?: 'tenant', '-');
    }
}
