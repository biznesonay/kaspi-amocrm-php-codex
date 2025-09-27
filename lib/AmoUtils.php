<?php
declare(strict_types=1);

function normalizeAmoSubdomain(string $raw): string {
    $normalized = trim($raw);
    if ($normalized === '') {
        return '';
    }

    $host = parse_url($normalized, PHP_URL_HOST);
    if ($host === false) {
        $host = null;
    }
    if ($host === null || $host === '') {
        $host = parse_url('https://'.ltrim($normalized, '/'), PHP_URL_HOST);
        if ($host === false) {
            $host = null;
        }
    }
    if (is_string($host) && $host !== '') {
        $normalized = $host;
    } else {
        $normalized = rtrim($normalized, "/\\");
    }

    $normalized = trim($normalized);
    $stripped = preg_replace('~\.amocrm(?:\.[a-z0-9-]+)+$~i', '', $normalized, -1, $count);
    if ($stripped !== null && $count > 0) {
        $normalized = $stripped;
    }

    return trim($normalized);
}
