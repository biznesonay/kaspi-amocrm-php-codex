<?php
declare(strict_types=1);

function env(string $key, $default=null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $envFile = __DIR__.'/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$k, $vRaw] = explode('=', $line, 2);
                $k = trim($k);
                $vRaw = ltrim($vRaw);
                if ($k === '') {
                    continue;
                }
                if ($vRaw === '') {
                    $cache[$k] = '';
                    continue;
                }
                $value = '';
                $len = strlen($vRaw);
                $quoted = false;
                $doubleQuoted = false;
                $inQuote = false;
                $quoteChar = '';
                for ($i = 0; $i < $len; $i++) {
                    $ch = $vRaw[$i];
                    if ($inQuote) {
                        if ($quoteChar === '"' && $ch === '\\' && $i + 1 < $len) {
                            $value .= $ch.$vRaw[$i + 1];
                            $i++;
                            continue;
                        }
                        if ($ch === $quoteChar) {
                            $inQuote = false;
                            $quoteChar = '';
                            continue;
                        }
                        $value .= $ch;
                        continue;
                    }
                    if (ctype_space($ch)) {
                        $rest = ltrim(substr($vRaw, $i + 1));
                        if ($rest !== '' && $rest[0] === '#') {
                            break;
                        }
                    }
                    if (($ch === '"' || $ch === "'") && $value === '') {
                        $inQuote = true;
                        $quoteChar = $ch;
                        $quoted = true;
                        if ($ch === '"') {
                            $doubleQuoted = true;
                        }
                        continue;
                    }
                    if ($ch === '#') {
                        $prev = $i > 0 ? $vRaw[$i - 1] : ' ';
                        if (ctype_space($prev)) {
                            break;
                        }
                    }
                    $value .= $ch;
                }
                $value = $quoted ? $value : rtrim($value);
                if ($doubleQuoted) {
                    $value = stripcslashes($value);
                }
                $cache[$k] = $value;
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
