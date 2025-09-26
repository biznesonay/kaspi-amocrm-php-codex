<?php
declare(strict_types=1);

final class Phone {
    public static function toE164(string $raw, string $defaultCountry='KZ'): string {
        $digits = preg_replace('/\D+/', '', $raw);
        // Kazakhstan default (+7)
        if ($defaultCountry === 'KZ') {
            if (strlen($digits) === 11 && $digits[0] === '8') {
                $digits = '7'.substr($digits,1);
            }
            if (strlen($digits) === 11 && $digits[0] === '7') {
                return '+'.$digits;
            }
            if (strlen($digits) === 10) {
                return '+7'.$digits;
            }
        }
        if (strlen($digits) > 0 && $digits[0] != '+') {
            return '+'.$digits;
        }
        return $raw;
    }
}
