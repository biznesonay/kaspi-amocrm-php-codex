<?php
declare(strict_types=1);

require_once __DIR__.'/../lib/PayloadBuilder.php';

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message."\n");
        exit(1);
    }
}

$contact = amoBuildContactPayload('Kaspi', 'Customer', null, [], []);
assertTrue(!isset($contact['_embedded']['tags']), 'У контакта не должно быть тегов в _embedded, если массив тегов пуст');
assertTrue(!isset($contact['tags']), 'Корневой ключ tags не должен присутствовать у контакта');
assertTrue(!array_key_exists('custom_fields_values', $contact), 'custom_fields_values не должен присутствовать, если массив пуст');

$contactJson = json_encode($contact, JSON_UNESCAPED_UNICODE);
assertTrue($contactJson !== false && strpos($contactJson, 'custom_fields_values') === false, 'JSON контакта не должен содержать custom_fields_values');

$customField = [
    'field_id' => 123,
    'values' => [['value' => 'Sample']],
];
$contactWithFields = amoBuildContactPayload('Kaspi', 'Customer', 42, [$customField], [['name' => 'Kaspi']]);
assertTrue(array_key_exists('custom_fields_values', $contactWithFields), 'custom_fields_values должен присутствовать, если массив не пуст');
assertTrue(isset($contactWithFields['_embedded']['tags']), 'У контакта должны быть теги в _embedded, если они переданы');

$lead = amoBuildLeadPayload(
    'Kaspi Order 123',
    15000,
    null,
    null,
    null,
    [],
    [['id' => 100500]],
    [
        ['name' => 'Kaspi'],
        ['name' => 'Marketplace'],
    ]
);
assertTrue(isset($lead['_embedded']['tags']), 'У сделки отсутствуют теги в _embedded');
assertTrue(isset($lead['_embedded']['contacts']), 'У сделки должны быть контакты в _embedded');
assertTrue(!isset($lead['tags']), 'Корневой ключ tags не должен присутствовать у сделки');

echo "PayloadTagsTest: OK\n";
