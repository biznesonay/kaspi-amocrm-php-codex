<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
require_once __DIR__.'/../lib/Logger.php';

Logger::info('Reconcile orders: start');

$kaspi = new KaspiClient();
$amo   = new AmoClient();
$pdo   = Db::pdo();

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
    $stmt = $pdo->prepare("SELECT lead_id FROM orders_map WHERE order_code=:c");
    $stmt->execute([':c'=>$code]);
    $row = $stmt->fetch();
    if (!$row) continue;
    $leadId = (int)$row['lead_id'];
    if ($leadId <= 0) continue;

    // Compare & update lead price if changed
    $sum = (int) ($attrs['totalPrice'] ?? 0);
    try {
        $amo->updateLead($leadId, ['price'=>$sum]);
    } catch (Throwable $e) {
        Logger::error('Update lead failed', ['leadId'=>$leadId, 'err'=>$e->getMessage()]);
    }

    // Refresh entries (re-link quantities)
    if ($catalogId > 0) {
        $entries = $kaspi->getOrderEntries($orderId);
        foreach ($entries as $e) {
            $eAttrs = $e['attributes'] ?? [];
            $qty = (int) ($eAttrs['quantity'] ?? 1);
            $title = (string) ($eAttrs['productName'] ?? ($eAttrs['name'] ?? 'Товар'));
            $sku = (string) ($eAttrs['productCode'] ?? ($eAttrs['code'] ?? $title));
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
    }

    $updated++;
}

Db::setSetting('last_check_ms', (string)$nowMs);
Logger::info('Reconcile orders: done', ['updated'=>$updated, 'new_last_check_ms'=>$nowMs]);
