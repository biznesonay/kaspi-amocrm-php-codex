<?php
declare(strict_types=1);

require_once __DIR__.'/../lib/AmoUtils.php';

$cases = [
    'https://demo.amocrm.com' => 'demo',
    'demo.amocrm.ua' => 'demo',
    'https://demo.amocrm.ru/' => 'demo',
];

foreach ($cases as $input => $expected) {
    $actual = normalizeAmoSubdomain($input);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "normalizeAmoSubdomain('%s') вернула '%s', ожидалось '%s'\n",
            $input,
            $actual,
            $expected
        ));
        exit(1);
    }
}

echo "NormalizeAmoSubdomainTest: OK\n";
