<?php
declare(strict_types=1);

final class Logger {
    private static function path(): string {
        $dir = __DIR__.'/../logs';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        return $dir.'/'.date('Y-m-d').'.log';
    }
    public static function info(string $msg, array $ctx=[]): void {
        self::write('INFO', $msg, $ctx);
    }
    public static function error(string $msg, array $ctx=[]): void {
        self::write('ERROR', $msg, $ctx);
    }
    private static function write(string $level, string $msg, array $ctx): void {
        $line = sprintf("%s [%s] %s %s
", date('c'), $level, $msg, $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE): '');
        file_put_contents(self::path(), $line, FILE_APPEND);
    }
}
