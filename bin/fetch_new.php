<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';

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

    // find or create contact
    $contactId = null;
    if ($phone) {
        $found = $amo->findContactByQuery($phone);
        if ($found) $contactId = (int)$found['id'];
    }
    if (!$contactId) {
        $first = $attrs['customer']['firstName'] ?? ($attrs['firstName'] ?? 'Kaspi');
        $last  = $attrs['customer']['lastName']  ?? ($attrs['lastName'] ?? 'Customer');
        $contactPayload = [[
            'first_name' => (string)$first,
            'last_name'  => (string)$last,
            'responsible_user_id' => $respUserId ?: null,
            'custom_fields_values' => $phone ? [[
                'field_code' => 'PHONE',
                'values' => [['value' => $phone]]
            ]] : null,
            'tags' => [['name' => 'Kaspi']],
        ]];
        $contactRes = $amo->createContacts($contactPayload);
        $createdContact = $contactRes['_embedded']['contacts'][0] ?? null;
        $contactId = $createdContact ? (int)$createdContact['id'] : null;
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
    if ($orderCodeFieldId) {
        $lead['custom_fields_values'] = [[
            'field_id' => $orderCodeFieldId,
            'values' => [['value' => $code]]
        ]];
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
