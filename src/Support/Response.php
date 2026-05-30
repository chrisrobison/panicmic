<?php

declare(strict_types=1);

namespace PanicMic\Support;

final class Response
{
    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR);
        exit;
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . Url::path($path), true, 302);
        exit;
    }
}

