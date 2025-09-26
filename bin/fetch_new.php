<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';

if (!function_exists('formatKaspiDeliveryAddress')) {
    /**
     * Normalize Kaspi order delivery address structure to a single string suitable for amoCRM fields.
     * Known keys: formattedAddress, region, city, district, street, streetNumber, house, building, block,
     * apartment, entrance, floor, comment. Unknown scalar values are appended as a fallback.
     */
    function formatKaspiDeliveryAddress(array $attrs): string {
        $delivery = $attrs['deliveryAddress'] ?? null;
        if (is_string($delivery)) {
            return trim($delivery);
        }
        if (!is_array($delivery)) {
            return '';
        }

        $formatted = trim((string)($delivery['formattedAddress'] ?? ''));
        if ($formatted !== '') {
            return $formatted;
        }

        $parts = [];
        $map = [
            'region' => '%s',
            'city' => '%s',
            'district' => '%s',
            'street' => 'ул. %s',
            'streetNumber' => 'д. %s',
            'house' => 'д. %s',
            'building' => 'стр. %s',
            'block' => 'корп. %s',
            'apartment' => 'кв. %s',
            'entrance' => 'подъезд %s',
            'floor' => 'этаж %s',
            'comment' => '%s',
        ];
        foreach ($map as $key => $mask) {
            if (!isset($delivery[$key])) continue;
            $value = trim((string)$delivery[$key]);
            if ($value === '') continue;
            $parts[] = sprintf($mask, $value);
        }

        if (!$parts) {
            $fallback = array_filter(array_map(static fn($v) => is_scalar($v) ? trim((string)$v) : '', $delivery));
            return $fallback ? implode(', ', $fallback) : '';
        }

        return implode(', ', $parts);
    }
}

Logger::info('Fetch new orders: start');

$kaspi = new KaspiClient();
$amo   = new AmoClient();
$pdo   = Db::pdo();

$watermark = (int) (Db::getSetting('last_creation_ms', '0') ?? '0');
$previousWatermark = $watermark;
$nowMs = (int) (microtime(true) * 1000);

$filters = [
    'filter[orders][creationDate][$ge]' => $watermark,
    'filter[orders][state]' => 'NEW', // adjust as needed
];

$pipelineId = (int) env('AMO_PIPELINE_ID', '0');
$statusId   = (int) env('AMO_STATUS_ID', '0');
$respUserId = (int) env('AMO_RESPONSIBLE_USER_ID', '0');
$catalogId  = (int) env('AMO_CATALOG_ID', '0');
$orderCodeFieldId = (int) env('AMO_LEAD_ORDER_CODE_FIELD_ID', '0');
$contactAddressFieldId = (int) env('AMO_CONTACT_ADDRESS_FIELD_ID', '0');
$leadDeliveryAddressFieldId = (int) env('AMO_LEAD_DELIVERY_ADDRESS_FIELD_ID', '0');

$created = 0;

foreach ($kaspi->listOrders($filters, 100) as $order) {
    $attrs = $order['attributes'] ?? [];
    $orderId = $order['id'] ?? '';
    $code = $attrs['code'] ?? '';
    if (!$code) continue;

    // anti-duplicate check in DB
    $stmt = $pdo->prepare("SELECT lead_id FROM orders_map WHERE order_code=:c");
    $stmt->execute([':c'=>$code]);
    $row = $stmt->fetch();
    if ($row && (int)$row['lead_id'] > 0) continue; // already processed

    $customer = $order['relationships']['user']['data']['id'] ?? null;
    $phoneRaw = $attrs['customer']['cellPhone'] ?? ($attrs['cellPhone'] ?? '');
    $phone = $phoneRaw ? normalizePhone((string)$phoneRaw) : '';
    $deliveryAddress = formatKaspiDeliveryAddress($attrs);

    // find or create contact
    $contactId = null;
    $foundContact = null;
    if ($phone) {
        $foundContact = $amo->findContactByQuery($phone);
        if ($foundContact) $contactId = (int)$foundContact['id'];
    }
    if (!$contactId) {
        $first = $attrs['customer']['firstName'] ?? ($attrs['firstName'] ?? 'Kaspi');
        $last  = $attrs['customer']['lastName']  ?? ($attrs['lastName'] ?? 'Customer');
        $contactCustomFields = [];
        if ($phone) {
            $contactCustomFields[] = [
                'field_code' => 'PHONE',
                'values' => [['value' => $phone]],
            ];
        }
        if ($contactAddressFieldId && $deliveryAddress) {
            $contactCustomFields[] = [
                'field_id' => $contactAddressFieldId,
                'values' => [['value' => $deliveryAddress]],
            ];
        }
        $contactPayload = [[
            'first_name' => (string)$first,
            'last_name'  => (string)$last,
            'responsible_user_id' => $respUserId ?: null,
            'custom_fields_values' => $contactCustomFields ?: null,
            'tags' => [['name' => 'Kaspi']],
        ]];
        $contactRes = $amo->createContacts($contactPayload);
        $createdContact = $contactRes['_embedded']['contacts'][0] ?? null;
        $contactId = $createdContact ? (int)$createdContact['id'] : null;
    } elseif ($contactAddressFieldId && $deliveryAddress && $foundContact) {
        $existingAddress = '';
        $cfValues = $foundContact['custom_fields_values'] ?? [];
        foreach ($cfValues as $cf) {
            if ((int)($cf['field_id'] ?? 0) !== $contactAddressFieldId) continue;
            $existingAddress = trim((string)($cf['values'][0]['value'] ?? ''));
            break;
        }
        if ($existingAddress !== $deliveryAddress) {
            $amo->updateContact($contactId, [
                'custom_fields_values' => [[
                    'field_id' => $contactAddressFieldId,
                    'values' => [['value' => $deliveryAddress]],
                ]],
            ]);
        }
    }

    // create lead
    $leadName = 'Kaspi Order '.$code;
    $price = (int) ($attrs['totalPrice'] ?? 0);
    $lead = [
        'name' => $leadName,
        'price'=> $price,
        'pipeline_id' => $pipelineId ?: null,
        'status_id' => $statusId ?: null,
        'responsible_user_id' => $respUserId ?: null,
        'tags' => [ ['name'=>'Kaspi'], ['name'=>'Marketplace'] ],
        '_embedded' => [
            'contacts' => $contactId ? [ ['id'=>$contactId] ] : [],
        ],
    ];
    $leadCustomFields = [];
    if ($orderCodeFieldId) {
        $leadCustomFields[] = [
            'field_id' => $orderCodeFieldId,
            'values' => [['value' => $code]],
        ];
    }
    if ($leadDeliveryAddressFieldId && $deliveryAddress) {
        $leadCustomFields[] = [
            'field_id' => $leadDeliveryAddressFieldId,
            'values' => [['value' => $deliveryAddress]],
        ];
    }
    if ($leadCustomFields) {
        $lead['custom_fields_values'] = $leadCustomFields;
    }
    $leadRes = $amo->createLeads([$lead]);
    $leadId = (int) ($leadRes['_embedded']['leads'][0]['id'] ?? 0);
    if ($leadId <= 0) {
        Logger::error('Lead creation failed', ['code'=>$code]);
        continue;
    }

    // store map
    $stmt = $pdo->prepare("INSERT INTO orders_map(order_code, kaspi_order_id, lead_id, created_at) VALUES(:c,:o,:l, NOW())");
    $stmt->execute([':c'=>$code, ':o'=>$orderId, ':l'=>$leadId]);

    // add items
    if ($catalogId > 0) {
        $entries = $kaspi->getOrderEntries($orderId);
        $lines = [];
        foreach ($entries as $e) {
            $eAttrs = $e['attributes'] ?? [];
            $qty = (int) ($eAttrs['quantity'] ?? 1);
            $title = (string) ($eAttrs['productName'] ?? ($eAttrs['name'] ?? 'Товар'));
            $sku = (string) ($eAttrs['productCode'] ?? ($eAttrs['code'] ?? $title));
            $priceItem = (int) ($eAttrs['basePrice'] ?? ($eAttrs['totalPrice'] ?? 0));
            $lines[] = [$sku, $qty, $priceItem];

            // find or create catalog element by SKU or title
            $found = $amo->findCatalogElement($catalogId, $sku ?: $title);
            if (!$found) {
                $cf = [];
                if ($sku) $cf[] = ['field_code'=>'SKU','values'=>[['value'=>$sku]]];
                if ($priceItem) $cf[] = ['field_code'=>'PRICE','values'=>[['value'=>$priceItem]]];
                $found = $amo->createCatalogElement($catalogId, $title, $cf);
            }
            if ($found && isset($found['id'])) {
                $amo->linkLeadToCatalogElement($leadId, $catalogId, (int)$found['id'], max(1,$qty));
            }
        }
        // summary note
        if ($lines) {
            $text = "Позиции заказа:\nSKU | Qty | Price\n";
            foreach ($lines as [$s,$q,$p]) { $text .= "{$s} | {$q} | {$p}\n"; }
            $amo->addNote($leadId, $text);
        }
    }

    $created++;
    $creationDate = (int) ($attrs['creationDate'] ?? 0);
    if ($creationDate > $watermark) {
        $watermark = $creationDate;
    }
}

$storedWatermark = $created > 0 ? $watermark : $previousWatermark;
Db::setSetting('last_creation_ms', (string)$storedWatermark);
Logger::info('Fetch new orders: done', [
    'created' => $created,
    'stored_watermark' => $storedWatermark,
]);
