<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PanicMic\Support\Env;

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

    /**
     * Magic-byte-detected MIME values that are acceptable for each
     * uploaded extension. libmagic returns slightly different strings
     * across platforms (e.g. .mov can come back as video/quicktime,
     * video/mp4, or application/octet-stream depending on container
     * variant), so each extension lists every known-good value.
     *
     * @var array<string, list<string>>
     */
    private const DETECTED_MIME_WHITELIST = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm', 'video/x-matroska'],
        'mov'  => ['video/quicktime', 'video/mp4'],
        'mp3'  => ['audio/mpeg', 'audio/mp3'],
        'wav'  => ['audio/wav', 'audio/x-wav', 'audio/wave'],
        'pdf'  => ['application/pdf'],
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

    /**
     * @param array<string,mixed> $upload
     * @param list<string>|null $allowedExtensions
     */
    public static function storeUpload(
        string $accountName,
        array $upload,
        ?array $allowedExtensions = null,
        ?int $maxBytes = null,
    ): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Upload failed');
        }
        $original = basename((string)$upload['name']);
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::MIME_TYPES[$extension])) {
            throw new \InvalidArgumentException('Unsupported file type');
        }
        if ($allowedExtensions !== null && !in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException('That file type is not allowed for this upload');
        }
        $maxBytes ??= self::configuredUploadLimit($extension);
        $actualSize = filesize((string)$upload['tmp_name']);
        if ($actualSize === false || $actualSize <= 0) {
            throw new \InvalidArgumentException('Uploaded file is empty or unreadable');
        }
        if ($actualSize > $maxBytes) {
            throw new \InvalidArgumentException(
                'File exceeds the ' . max(1, (int)floor($maxBytes / 1048576)) . ' MB upload limit'
            );
        }
        // Magic-byte check: reject a file whose actual content doesn't
        // match its claimed extension (e.g. a renamed .exe → .png).
        self::verifyMagicBytes((string)$upload['tmp_name'], $extension);
        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME)) ?: 'file';
        $filename = trim($safeBase, '.-') . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
        $dir = self::ensureTenantDirectory($accountName);
        $destination = $dir . '/' . $filename;
        if (!move_uploaded_file((string)$upload['tmp_name'], $destination)) {
            throw new \RuntimeException('Unable to save uploaded file');
        }
        chmod($destination, 0664);
        return [
            'name' => $filename,
            'url' => '/files/' . rawurlencode($filename),
            'size' => filesize($destination),
            'mime' => self::MIME_TYPES[$extension],
        ];
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
        $size = filesize($file);
        if ($size === false) {
            http_response_code(404);
            exit;
        }
        $start = 0;
        $end = $size - 1;
        header('Content-Type: ' . (self::MIME_TYPES[$extension] ?? 'application/octet-stream'));
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=3600');
        $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '') {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match)) {
                header("Content-Range: bytes */{$size}");
                http_response_code(416);
                exit;
            }
            if ($match[1] === '' && $match[2] !== '') {
                $suffix = min($size, (int)$match[2]);
                $start = $size - $suffix;
            } else {
                $start = (int)$match[1];
                if ($match[2] !== '') {
                    $end = min($end, (int)$match[2]);
                }
            }
            if ($start < 0 || $start > $end || $start >= $size) {
                header("Content-Range: bytes */{$size}");
                http_response_code(416);
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        $length = $end - $start + 1;
        header('Content-Length: ' . $length);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            http_response_code(404);
            exit;
        }
        fseek($handle, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
            $chunk = fread($handle, min(1048576, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
        exit;
    }

    public static function safeAccountName(string $accountName): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower($accountName));
        return trim($safe ?: 'tenant', '-');
    }

    /**
     * Detect the actual MIME type of a file from its bytes and confirm
     * it matches one of the values whitelisted for the claimed
     * extension. Throws InvalidArgumentException on mismatch.
     */
    public static function verifyMagicBytes(string $path, string $extension): void
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Uploaded file is unreadable');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            // No libmagic — fail closed.
            throw new \RuntimeException('Server cannot verify uploaded file types');
        }
        // finfo objects are freed automatically (PHP 8.5+), so no explicit close.
        $detected = (string)finfo_file($finfo, $path);
        $allowed = self::DETECTED_MIME_WHITELIST[$extension] ?? [];
        if (!in_array($detected, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Uploaded file content (%s) does not match its .%s extension',
                $detected ?: 'unknown',
                $extension,
            ));
        }
    }

    private static function configuredUploadLimit(string $extension): int
    {
        $video = in_array($extension, ['mp4', 'webm', 'mov'], true);
        $key = $video ? 'VIDEO_UPLOAD_MAX_MB' : 'CONTENT_UPLOAD_MAX_MB';
        $default = $video ? 1024 : 25;
        $megabytes = max(1, (int)(Env::get($key, (string)$default) ?? (string)$default));
        return $megabytes * 1048576;
    }
}
