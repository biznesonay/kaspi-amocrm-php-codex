<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/Logger.php';

final class KaspiClient {
    private string $base;
    private string $token;

    public function __construct() {
        $this->base = rtrim(env('KASPI_API_BASE', 'https://kaspi.kz/shop/api/v2'), '/');
        $this->token = (string) env('KASPI_API_TOKEN', '');
        if ($this->token === '') {
            throw new RuntimeException('KASPI_API_TOKEN is empty');
        }
    }

    private function request(string $method, string $path, array $query = []): array {
        $url = $this->base . $path;
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/vnd.api+json',
                'Accept: application/vnd.api+json',
                'X-Auth-Token: '.$this->token,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Kaspi request failed: '.$err);
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new RuntimeException("Kaspi HTTP $code: $resp");
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new RuntimeException('Kaspi invalid JSON');
        }
        return $data;
    }

    public function listOrders(array $filters, int $pageSize = 100) : iterable {
        $page = 0;
        while (true) {
            $query = [
                'page[number]' => $page,
                'page[size]' => $pageSize,
            ];
            foreach ($filters as $k => $v) {
                $query[$k] = $v;
            }
            $data = $this->request('GET', '/orders', $query);
            $items = $data['data'] ?? [];
            if (!$items) break;
            foreach ($items as $item) yield $item;
            $page++;
            if (count($items) < $pageSize) break;
        }
    }

    public function getOrderEntries(string $orderId, int $pageSize = 100): iterable {
        $page = 0;
        while (true) {
            $query = [
                'page[number]' => $page,
                'page[size]' => $pageSize,
            ];
            $data = $this->request('GET', "/orders/{$orderId}/entries", $query);
            $items = $data['data'] ?? [];
            if (!$items) {
                break;
            }
            foreach ($items as $item) {
                yield $item;
            }
            $page++;
            if (count($items) < $pageSize) {
                break;
            }
        }
    }
}
