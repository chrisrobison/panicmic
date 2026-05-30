<?php

declare(strict_types=1);

namespace PanicMic\Support;

/**
 * HTML-escape a value for output in views. Safe to call with null.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
