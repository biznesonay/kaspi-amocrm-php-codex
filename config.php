<?php
declare(strict_types=1);

function env(string $key, $default=null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $envFile = __DIR__.'/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#')) continue;
                if (!str_contains($line, '=')) continue;
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                $cache[$k] = $v;
            }
        }
        // Fallback to system envs
        $cache = array_merge($cache, getenv());
    }
    return $cache[$key] ?? $default;
}

// Basic error handler
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

date_default_timezone_set('Asia/Almaty');
