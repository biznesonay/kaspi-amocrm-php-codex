<?php
declare(strict_types=1);

function normalizeAmoSubdomain(string $raw): string {
    $normalized = trim($raw);
    if ($normalized === '') {
        return '';
    }

    $lower = strtolower($normalized);
    if (str_starts_with($lower, 'https://')) {
        $normalized = substr($normalized, 8);
    } elseif (str_starts_with($lower, 'http://')) {
        $normalized = substr($normalized, 7);
    }

    $normalized = rtrim($normalized, "/\\");
    if (preg_match('~\.amocrm\.ru$~i', $normalized)) {
        $normalized = preg_replace('~\.amocrm\.ru$~i', '', $normalized);
    }

    return trim($normalized);
}
