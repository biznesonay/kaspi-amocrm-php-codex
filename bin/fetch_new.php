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

if (!function_exists('releaseOrderReservation')) {
    function releaseOrderReservation(PDO $pdo, string $code, string $processingToken): void {
        try {
            $stmt = $pdo->prepare(
                'UPDATE orders_map ' .
                'SET lead_id = 0, processing_token = NULL, processing_at = NULL ' .
                'WHERE order_code = :code AND processing_token = :token'
            );
            $stmt->execute([
                ':code' => $code,
                ':token' => $processingToken,
            ]);
        } catch (Throwable $e) {
            Logger::error('Failed to release order reservation', [
                'code' => $code,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }
}

Logger::info('Fetch new orders: start');

$kaspi = new KaspiClient();
$amo   = new AmoClient();
$pdo   = Db::pdo();

$watermark = (int) (Db::getSetting('last_creation_ms', '0') ?? '0');
$previousWatermark = $watermark;
$nowMs = (int) (microtime(true) * 1000);
$minAllowed = $nowMs - 14 * 24 * 3600 * 1000;
$creationDateFrom = max($watermark, $minAllowed);

$filters = [
    // Kaspi limits filtering by creationDate to the last 14 days
    'filter[orders][creationDate][$ge]' => $creationDateFrom,
    'filter[orders][creationDate][$le]' => $nowMs,
    'filter[orders][state]' => 'NEW', // adjust as needed
];

$pipelineId = (int) env('AMO_PIPELINE_ID', '0');
$statusId   = (int) env('AMO_STATUS_ID', '0');
$respUserId = (int) env('AMO_RESPONSIBLE_USER_ID', '0');
$catalogId  = (int) env('AMO_CATALOG_ID', '0');
$orderCodeFieldId = (int) env('AMO_LEAD_ORDER_CODE_FIELD_ID', '0');
$contactAddressFieldId = (int) env('AMO_CONTACT_ADDRESS_FIELD_ID', '0');
$leadDeliveryAddressFieldId = (int) env('AMO_LEAD_DELIVERY_ADDRESS_FIELD_ID', '0');
$leadOrderDateFieldId = (int) env('AMO_LEAD_ORDER_DATE_FIELD_ID', '0');
$projectTimezone = new DateTimeZone(date_default_timezone_get());
$utcTimezone = new DateTimeZone('UTC');

$created = 0;

foreach ($kaspi->listOrders($filters, 100) as $order) {
    $attrs = $order['attributes'] ?? [];
    $orderId = $order['id'] ?? '';
    $code = $attrs['code'] ?? '';
    if (!$code) continue;

    $creationDateMs = 0;
    $orderCreationDateIso = null;
    $creationDateRaw = $attrs['creationDate'] ?? null;
    if (is_numeric($creationDateRaw)) {
        $creationDateMs = (int) $creationDateRaw;
        if ($creationDateMs > 0) {
            $seconds = intdiv($creationDateMs, 1000);
            $milliseconds = $creationDateMs % 1000;
            $datetime = DateTimeImmutable::createFromFormat(
                'U.u',
                sprintf('%d.%03d', $seconds, $milliseconds),
                $utcTimezone
            );
            if ($datetime instanceof DateTimeImmutable) {
                $orderCreationDateIso = $datetime->setTimezone($projectTimezone)->format(DATE_ATOM);
            }
        }
    }

    $price = (int) ($attrs['totalPrice'] ?? 0);
    $processingToken = bin2hex(random_bytes(16));
    $leadId = 0;

    $reservationTransactionStarted = false;
    try {
        if (!$pdo->beginTransaction()) {
            Logger::error('Failed to start reservation transaction for order', ['code' => $code]);
            continue;
        }
        $reservationTransactionStarted = true;

        $stmtSelect = $pdo->prepare(
            'SELECT lead_id, processing_token FROM orders_map WHERE order_code = :code FOR UPDATE'
        );
        $stmtSelect->execute([':code' => $code]);
        $existing = $stmtSelect->fetch();

        if ($existing === false) {
            try {
                $stmtInsert = $pdo->prepare(
                    'INSERT INTO orders_map (order_code, kaspi_order_id, lead_id, total_price, created_at, processing_token, processing_at) ' .
                    'VALUES (:code, :order_id, 0, :total_price, NOW(), :token, NOW())'
                );
                $stmtInsert->execute([
                    ':code' => $code,
                    ':order_id' => (string) $orderId,
                    ':total_price' => $price,
                    ':token' => $processingToken,
                ]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                $reservationTransactionStarted = false;
                Logger::info('Order is already being processed or completed, skip lead creation', ['code' => $code]);
                continue;
            }
        } else {
            if ((int) ($existing['lead_id'] ?? 0) > 0) {
                $pdo->rollBack();
                $reservationTransactionStarted = false;
                Logger::info('Order already linked with a lead, skip', ['code' => $code]);
                continue;
            }

            $foreignToken = trim((string) ($existing['processing_token'] ?? ''));
            if ($foreignToken !== '' && $foreignToken !== $processingToken) {
                $pdo->rollBack();
                $reservationTransactionStarted = false;
                Logger::info('Order is already being processed by another worker, skip lead creation', ['code' => $code]);
                continue;
            }

            $stmtUpdateReserve = $pdo->prepare(
                'UPDATE orders_map ' .
                'SET kaspi_order_id = :order_id, total_price = :total_price, processing_token = :token, processing_at = NOW() ' .
                'WHERE order_code = :code'
            );
            $stmtUpdateReserve->execute([
                ':order_id' => (string) $orderId,
                ':total_price' => $price,
                ':token' => $processingToken,
                ':code' => $code,
            ]);
        }

        $pdo->commit();
        $reservationTransactionStarted = false;
    } catch (Throwable $e) {
        if ($reservationTransactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Logger::error('Failed to reserve order for processing', [
            'code' => $code,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]);
        continue;
    }

    $transactionStarted = false;

    try {
        if (!$pdo->beginTransaction()) {
            releaseOrderReservation($pdo, $code, $processingToken);
            Logger::error('Failed to start transaction for order', ['code' => $code]);
            continue;
        }
        $transactionStarted = true;

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
            $contactPayload = [amoBuildContactPayload(
                (string)$first,
                (string)$last,
                $respUserId ?: null,
                $contactCustomFields,
                []
            )];
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
        if ($leadOrderDateFieldId && $orderCreationDateIso) {
            $leadCustomFields[] = [
                'field_id' => $leadOrderDateFieldId,
                'values' => [['value' => $orderCreationDateIso]],
            ];
        }
        $lead = amoBuildLeadPayload(
            $leadName,
            $price,
            $pipelineId ?: null,
            $statusId ?: null,
            $respUserId ?: null,
            $leadCustomFields,
            $contactId ? [['id' => $contactId]] : [],
            [
                ['name' => 'Kaspi'],
                ['name' => 'Marketplace'],
            ]
        );
        $leadRes = $amo->createLeads([$lead]);
        $leadId = (int) ($leadRes['_embedded']['leads'][0]['id'] ?? 0);
        if ($leadId <= 0) {
            throw new RuntimeException('Lead creation failed');
        }

        // add items
        $lines = [];
        $hasEntries = false;
        foreach ($kaspi->getOrderEntries($orderId) as $e) {
            $hasEntries = true;
            $eAttrs = $e['attributes'] ?? [];
            $qty = (int) ($eAttrs['quantity'] ?? 1);
            $title = (string) ($eAttrs['productName'] ?? ($eAttrs['name'] ?? 'Товар'));
            $sku = (string) ($eAttrs['productCode'] ?? ($eAttrs['code'] ?? $title));
            $priceItem = (int) ($eAttrs['basePrice'] ?? ($eAttrs['totalPrice'] ?? 0));
            $lines[] = [$title, $sku, $qty, $priceItem];

            if ($catalogId > 0) {
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
        }
        // summary note
        if ($hasEntries && $lines) {
            $text = "Позиции заказа:\nName | SKU | Qty | Price\n";
            foreach ($lines as [$n,$s,$q,$p]) { $text .= "{$n} | {$s} | {$q} | {$p}\n"; }
            $amo->addNote($leadId, $text);
        } elseif (!$hasEntries) {
            Logger::info('Order has no entries', ['order_id' => $orderId, 'code' => $code]);
        }

        // store map
        $stmt = $pdo->prepare('UPDATE orders_map SET kaspi_order_id=:o, lead_id=:l, total_price=:p, processing_token=NULL, processing_at=NULL WHERE order_code=:c');
        $stmt->execute([':c'=>$code, ':o'=>$orderId, ':l'=>$leadId, ':p'=>$price]);

        $pdo->commit();
        $transactionStarted = false;

        $created++;
        if ($creationDateMs > $watermark) {
            $watermark = $creationDateMs;
        }
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($leadId > 0) {
            try {
                $amo->deleteLead($leadId);
            } catch (Throwable $deleteError) {
                Logger::error('Failed to delete lead after rollback', [
                    'code' => $code,
                    'lead_id' => $leadId,
                    'error' => $deleteError->getMessage(),
                    'exception' => get_class($deleteError),
                ]);
            }
        }

        releaseOrderReservation($pdo, $code, $processingToken);

        Logger::error('Failed to process order', [
            'code' => $code,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]);
        continue;
    }
}

$storedWatermark = $created > 0 ? $watermark : $previousWatermark;
Db::setSetting('last_creation_ms', (string)$storedWatermark);
Logger::info('Fetch new orders: done', [
    'created' => $created,
    'stored_watermark' => $storedWatermark,
]);
