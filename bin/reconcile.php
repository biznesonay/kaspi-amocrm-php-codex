<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
require_once __DIR__.'/../lib/Logger.php';

Logger::info('Reconcile orders: start');

$kaspi = new KaspiClient();
$amo   = new AmoClient();
$pdo   = Db::pdo();
$statusMappingManager = new StatusMappingManager();
$productCache = [];

$catalogId  = (int) env('AMO_CATALOG_ID', '0');

$lastCheck = (int) (Db::getSetting('last_check_ms', '0') ?? '0');
$nowMs = (int) (microtime(true) * 1000);

// Strategy: fetch last 7 days orders to catch updates
$sevenDaysAgo = $nowMs - 7*24*3600*1000;
$from = max($sevenDaysAgo, $lastCheck);

$filters = [
    'filter[orders][creationDate][$ge]' => $from,
    'filter[orders][creationDate][$le]' => $nowMs,
];

$updated = 0;

foreach ($kaspi->listOrders($filters, 100) as $order) {
    $attrs = $order['attributes'] ?? [];
    $orderId = $order['id'] ?? '';
    $code = $attrs['code'] ?? '';
    if (!$code) continue;

    // find mapping
    $stmt = $pdo->prepare("SELECT lead_id, total_price, kaspi_status FROM orders_map WHERE order_code=:c");
    $stmt->execute([':c'=>$code]);
    $row = $stmt->fetch();
    if (!$row) continue;
    $leadId = (int)$row['lead_id'];
    if ($leadId <= 0) continue;

    $kaspiState = isset($attrs['state']) ? trim((string)$attrs['state']) : '';
    $storedKaspiStatus = isset($row['kaspi_status']) ? trim((string)$row['kaspi_status']) : '';

    if ($kaspiState !== '' && $kaspiState !== $storedKaspiStatus) {
        $mappedStatusId = $statusMappingManager->getAmoStatusId($kaspiState);
        if (is_int($mappedStatusId) && $mappedStatusId > 0) {
            try {
                $amo->updateLead($leadId, ['status_id' => $mappedStatusId]);
                $stmtUpdateStatus = $pdo->prepare('UPDATE orders_map SET kaspi_status = :status WHERE order_code = :code');
                $stmtUpdateStatus->execute([
                    ':status' => $kaspiState,
                    ':code' => $code,
                ]);
                Logger::info('Order status changed', [
                    'lead_id' => $leadId,
                    'order_code' => $code,
                    'kaspi_status' => $kaspiState,
                    'amo_status_id' => $mappedStatusId,
                ]);
            } catch (Throwable $e) {
                Logger::error('Failed to update lead status', [
                    'lead_id' => $leadId,
                    'order_code' => $code,
                    'kaspi_status' => $kaspiState,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }
        }
    }

    // Compare & update lead price if changed
    $sum = (int) ($attrs['totalPrice'] ?? 0);
    $storedTotal = $row['total_price'] ?? null;
    $hasStoredTotal = $storedTotal !== null;
    $storedTotalInt = $hasStoredTotal ? (int)$storedTotal : null;
    $needsLeadUpdate = !$hasStoredTotal || $storedTotalInt !== $sum;

    if ($needsLeadUpdate) {
        try {
            $amo->updateLead($leadId, ['price'=>$sum]);
            $stmtUpdate = $pdo->prepare("UPDATE orders_map SET total_price=:p WHERE order_code=:c");
            $stmtUpdate->execute([':p'=>$sum, ':c'=>$code]);
            $updated++;
        } catch (Throwable $e) {
            Logger::error('Update lead failed', ['leadId'=>$leadId, 'err'=>$e->getMessage()]);
        }
    }

    // Refresh entries (re-link quantities)
    if ($catalogId > 0) {
        $hasEntries = false;
        foreach ($kaspi->getOrderEntries($orderId) as $e) {
            $hasEntries = true;
            $eAttrs = $e['attributes'] ?? [];
            if (!is_array($eAttrs)) {
                $eAttrs = [];
            }
            $qty = (int) ($eAttrs['quantity'] ?? 1);
            [$title, $sku] = resolveOrderEntryProductDetails($kaspi, $e, $productCache);
            $priceItem = (int) ($eAttrs['basePrice'] ?? ($eAttrs['totalPrice'] ?? 0));

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
        if (!$hasEntries) {
            Logger::info('Order has no entries during reconcile', ['order_id' => $orderId, 'code' => $code]);
        }
    }

}

Db::setSetting('last_check_ms', (string)$nowMs);
Logger::info('Reconcile orders: done', ['updated'=>$updated, 'new_last_check_ms'=>$nowMs]);
