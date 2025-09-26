<?php
declare(strict_types=1);

use Throwable;

final class Logger {
    private static bool $fallbackUsed = false;

    private static function path(): ?string {
        $dir = __DIR__.'/../logs';
        if (!is_dir($dir)) {
            try {
                mkdir($dir, 0775, true);
            } catch (Throwable $e) {
                self::$fallbackUsed = true;
                self::reportFailure('Failed to create log directory', $e, $dir);
                return null;
            }
        }

        return $dir.'/'.date('Y-m-d').'.log';
    }
    public static function info(string $msg, array $ctx=[]): void {
        self::write('INFO', $msg, $ctx);
    }
    public static function error(string $msg, array $ctx=[]): void {
        self::write('ERROR', $msg, $ctx);
    }
    private static function reportFailure(string $message, Throwable $e, ?string $path = null): void {
        $errorMessage = $message;
        if ($path !== null) {
            $errorMessage .= sprintf(' (path: %s)', $path);
        }
        $errorMessage .= ': '.$e->getMessage();

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $errorMessage.PHP_EOL);
        } else {
            error_log($errorMessage);
        }
    }

    private static function fallback(string $line): void {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $line);
        } else {
            error_log($line);
        }
    }

    private static function write(string $level, string $msg, array $ctx): void {
        $context = $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : '';
        $line = sprintf("%s [%s] %s %s%s", date('c'), $level, $msg, $context, PHP_EOL);

        if (self::$fallbackUsed) {
            self::fallback($line);
            return;
        }

        $path = self::path();
        if ($path === null || self::$fallbackUsed) {
            self::fallback($line);
            return;
        }

        try {
            file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            self::$fallbackUsed = true;
            self::reportFailure('Failed to write log file', $e, $path);
            self::fallback($line);
        }
    }
}
