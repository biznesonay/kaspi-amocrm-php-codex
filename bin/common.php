<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../lib/Db.php';
require_once __DIR__.'/../lib/Logger.php';
require_once __DIR__.'/../lib/Phone.php';
require_once __DIR__.'/../lib/KaspiClient.php';
require_once __DIR__.'/../lib/AmoClient.php';
require_once __DIR__.'/../lib/PayloadBuilder.php';

function normalizePhone(string $raw): string {
    $def = env('DEFAULT_COUNTRY', 'KZ');
    return Phone::toE164($raw, $def);
}

function resolveOrderEntryProductDetails(KaspiClient $kaspi, array $entry, array &$productCache): array {
    $attributes = $entry['attributes'] ?? [];
    if (!is_array($attributes)) {
        $attributes = [];
    }

    $title = trim((string) ($attributes['productName'] ?? ($attributes['name'] ?? '')));
    $sku = trim((string) ($attributes['productCode'] ?? ($attributes['code'] ?? '')));
    $entryId = isset($entry['id']) ? trim((string) $entry['id']) : '';

    if ((!$title || !$sku) && $entryId !== '') {
        if (!array_key_exists($entryId, $productCache)) {
            try {
                $productCache[$entryId] = $kaspi->getOrderEntryProduct($entryId);
            } catch (Throwable $e) {
                Logger::error('Failed to fetch Kaspi product for order entry', [
                    'entry_id' => $entryId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                $productCache[$entryId] = null;
            }
        }

        $product = $productCache[$entryId];
        if (is_array($product)) {
            $productAttributes = $product['attributes'] ?? [];
            if (!is_array($productAttributes)) {
                $productAttributes = [];
            }
            if ($title === '') {
                $title = trim((string) ($productAttributes['name'] ?? ($productAttributes['productName'] ?? '')));
            }
            if ($sku === '') {
                $sku = trim((string) ($productAttributes['code'] ?? ($productAttributes['productCode'] ?? '')));
            }
        }
    }

    if ($title === '') {
        $title = 'Товар';
    }
    if ($sku === '') {
        $sku = $title;
    }

    return [$title, $sku];
}

