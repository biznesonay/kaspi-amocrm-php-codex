<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/Db.php';
require_once __DIR__.'/Logger.php';
require_once __DIR__.'/RateLimiter.php';
require_once __DIR__.'/AmoUtils.php';

final class AmoClient {
    private string $subdomain;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private RateLimiter $limiter;

    public function __construct() {
        $rawSubdomain = (string) env('AMO_SUBDOMAIN', '');
        $this->subdomain   = normalizeAmoSubdomain($rawSubdomain);
        $this->clientId    = trim((string) env('AMO_CLIENT_ID', ''));
        $this->clientSecret= trim((string) env('AMO_CLIENT_SECRET', ''));
        $this->redirectUri = trim((string) env('AMO_REDIRECT_URI', ''));
        $this->limiter = new RateLimiter(7.0);
        if ($this->subdomain === '') {
            Logger::error('AMO_SUBDOMAIN is empty after normalisation', ['value' => $rawSubdomain]);
            throw new RuntimeException('AMO_SUBDOMAIN is empty');
        }
        if (str_contains($this->subdomain, '.')) {
            Logger::error('AMO_SUBDOMAIN still contains dots after normalisation', ['value' => $rawSubdomain, 'normalized' => $this->subdomain]);
            throw new RuntimeException('AMO_SUBDOMAIN must not contain dots');
        }
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $this->subdomain)) {
            Logger::error('AMO_SUBDOMAIN contains invalid characters', ['value' => $rawSubdomain, 'normalized' => $this->subdomain]);
            throw new RuntimeException('AMO_SUBDOMAIN must contain only letters, digits, or hyphen');
        }
        // bootstrap from .env if presents (only until DB row is created)
        $envAccess  = env('AMO_ACCESS_TOKEN', '');
        $envRefresh = env('AMO_REFRESH_TOKEN', '');
        $envExp     = (int) env('AMO_EXPIRES_AT', '0');
        if ($envAccess && $envRefresh) {
            $stmt = Db::pdo()->prepare("SELECT 1 FROM oauth_tokens WHERE service = 'amocrm' LIMIT 1");
            $stmt->execute();
            $exists = (bool) $stmt->fetchColumn();
            if (!$exists) {
                $this->saveTokens($envAccess, $envRefresh, $envExp ?: (time()+3600));
            }
        }
    }

    private function tokens(): array {
        $stmt = Db::pdo()->prepare("SELECT access_token, refresh_token, expires_at FROM oauth_tokens WHERE service = 'amocrm'");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('No amoCRM tokens. Run OAuth setup.');
        return $row;
    }

    private function saveTokens(string $access, string $refresh, int $expires): void {
        $driver = env('DB_DRIVER', 'mysql');
        if ($driver === 'pgsql') {
            $sql = "INSERT INTO oauth_tokens(service, access_token, refresh_token, expires_at) VALUES('amocrm', :a, :r, :e)
                    ON CONFLICT (service) DO UPDATE SET access_token=EXCLUDED.access_token, refresh_token=EXCLUDED.refresh_token, expires_at=EXCLUDED.expires_at";
        } else {
            $sql = "INSERT INTO oauth_tokens(service, access_token, refresh_token, expires_at) VALUES('amocrm', :a, :r, :e)
                    ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), refresh_token=VALUES(refresh_token), expires_at=VALUES(expires_at)";
        }
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([':a'=>$access, ':r'=>$refresh, ':e'=>$expires]);
    }

    private function apiUrl(string $path): string {
        return 'https://'.$this->subdomain.'.amocrm.ru'.$path;
    }

    private function authUrl(): string {
        return 'https://'.$this->subdomain.'.amocrm.ru/oauth2/access_token';
    }

    private function ensureAccessToken(): string {
        $t = $this->tokens();
        if ((int)$t['expires_at'] > time()+60) return $t['access_token'];
        // refresh
        $payload = json_encode([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $t['refresh_token'],
            'redirect_uri' => $this->redirectUri,
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($this->authUrl());
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) throw new RuntimeException('amoCRM refresh error: '.curl_error($ch));
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 400) throw new RuntimeException("amoCRM refresh HTTP $code: $resp");
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            Logger::error('amoCRM refresh invalid JSON', ['http_code' => $code, 'body' => $resp]);
            throw new RuntimeException('amoCRM refresh invalid JSON response: '.$resp);
        }

        $access = $data['access_token'] ?? null;
        $refresh= $data['refresh_token'] ?? null;
        if (!$access || !$refresh) {
            Logger::error('amoCRM refresh missing tokens', ['http_code' => $code, 'body' => $resp]);
            throw new RuntimeException('amoCRM refresh malformed: '.$resp);
        }

        $expiresIn = filter_var($data['expires_in'] ?? null, FILTER_VALIDATE_INT);
        if ($expiresIn === false || $expiresIn === null) {
            Logger::error('amoCRM refresh invalid expires_in', ['http_code' => $code, 'body' => $resp]);
            throw new RuntimeException('amoCRM refresh invalid expires_in: '.$resp);
        }

        $exp    = time() + $expiresIn;
        $this->saveTokens($access, $refresh, $exp);
        return $access;
    }

    private function request(string $method, string $path, ?array $body=null, array $query=[]): array {
        $this->limiter->throttle();
        $url = $this->apiUrl($path);
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $access = $this->ensureAccessToken();
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer '.$access,
        ];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('amoCRM request failed: '.$err);
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 204) return [];
        if ($code >= 400) {
            throw new RuntimeException("amoCRM HTTP $code: $resp");
        }
        $data = json_decode($resp, true);
        return is_array($data) ? $data : [];
    }

    # Public methods

    public function findContactByQuery(string $query): ?array {
        $res = $this->request('GET', '/api/v4/contacts', null, ['query' => $query, 'limit' => 1]);
        $list = $res['_embedded']['contacts'] ?? [];
        return $list ? $list[0] : null;
    }

    public function createContacts(array $contacts): array {
        return $this->request('POST', '/api/v4/contacts', $contacts);
    }

    public function updateContact(int $id, array $fields): array {
        $fields['id'] = $id;
        return $this->request('PATCH', '/api/v4/contacts', [$fields]);
    }

    public function createLeads(array $leads): array {
        return $this->request('POST', '/api/v4/leads', $leads);
    }

    public function updateLead(int $id, array $fields): array {
        $fields['id'] = $id;
        return $this->request('PATCH', '/api/v4/leads', [$fields]);
    }

    public function deleteLead(int $id): void {
        $this->request('DELETE', "/api/v4/leads/{$id}");
    }

    public function linkLeadToCatalogElement(int $leadId, int $catalogId, int $elementId, int $qty): void {
        $payload = [ [ 'to_entity_id' => $elementId, 'to_entity_type' => 'catalog_elements',
                       'metadata' => ['quantity' => $qty, 'catalog_id' => $catalogId] ] ];
        $this->request('POST', "/api/v4/leads/{$leadId}/link", $payload);
    }

    public function addNote(int $leadId, string $text): void {
        $payload = [ [ 'note_type' => 'common', 'params' => ['text' => $text] ] ];
        $this->request('POST', "/api/v4/leads/{$leadId}/notes", $payload);
    }

    public function findCatalogElement(int $catalogId, string $query): ?array {
        $res = $this->request('GET', "/api/v4/catalogs/{$catalogId}/elements", null, ['query'=>$query, 'limit'=>1]);
        $list = $res['_embedded']['elements'] ?? [];
        return $list ? $list[0] : null;
    }

    public function createCatalogElement(int $catalogId, string $name, array $customFields=[]): array {
        $payload = [ [ 'name' => $name, 'custom_fields_values' => $customFields ] ];
        $res = $this->request('POST', "/api/v4/catalogs/{$catalogId}/elements", $payload);
        $list = $res['_embedded']['elements'] ?? [];
        return $list ? $list[0] : [];
    }

    /**
     * Возвращает список воронок amoCRM вместе с их статусами.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPipelines(): array {
        $page   = 1;
        $limit  = 50;
        $result = [];

        try {
            while (true) {
                $response = $this->request('GET', '/api/v4/leads/pipelines', null, [
                    'page' => $page,
                    'limit' => $limit,
                    'with' => 'leads_statuses',
                ]);

                if (!is_array($response)) {
                    Logger::error('amoCRM pipelines response is not an array', ['page' => $page, 'response_type' => gettype($response)]);
                    break;
                }

                $embedded = $response['_embedded'] ?? null;
                if (!is_array($embedded)) {
                    Logger::error('amoCRM pipelines response missing _embedded', ['page' => $page, 'response' => $response]);
                    break;
                }

                $pipelines = $embedded['pipelines'] ?? null;
                if (!is_array($pipelines)) {
                    Logger::error('amoCRM pipelines response missing pipelines array', ['page' => $page, 'embedded' => $embedded]);
                    break;
                }

                foreach ($pipelines as $pipeline) {
                    if (!is_array($pipeline)) {
                        Logger::error('amoCRM pipeline item is not an array', ['page' => $page, 'item_type' => gettype($pipeline)]);
                        continue;
                    }

                    $pipelineId = filter_var($pipeline['id'] ?? null, FILTER_VALIDATE_INT);
                    $pipelineName = isset($pipeline['name']) ? (string) $pipeline['name'] : null;
                    $pipelineSort = filter_var($pipeline['sort'] ?? null, FILTER_VALIDATE_INT);
                    $pipelineColor = isset($pipeline['color']) ? (string) $pipeline['color'] : '';

                    if ($pipelineId === false || $pipelineId === null || $pipelineName === null || $pipelineSort === false || $pipelineSort === null) {
                        Logger::error('amoCRM pipeline item missing required fields', ['page' => $page, 'pipeline' => $pipeline]);
                        continue;
                    }

                    $statusesRaw = $pipeline['_embedded']['statuses'] ?? $pipeline['statuses'] ?? null;
                    if ($statusesRaw !== null && !is_array($statusesRaw)) {
                        Logger::error('amoCRM pipeline statuses is not an array', ['pipeline_id' => $pipelineId, 'statuses_type' => gettype($statusesRaw)]);
                        $statusesRaw = [];
                    }

                    $statuses = [];
                    if (is_array($statusesRaw)) {
                        foreach ($statusesRaw as $status) {
                            if (!is_array($status)) {
                                Logger::error('amoCRM status item is not an array', ['pipeline_id' => $pipelineId, 'status_type' => gettype($status)]);
                                continue;
                            }

                            $statusId = filter_var($status['id'] ?? null, FILTER_VALIDATE_INT);
                            $statusName = isset($status['name']) ? (string) $status['name'] : null;
                            $statusSort = filter_var($status['sort'] ?? null, FILTER_VALIDATE_INT);
                            $statusColor = isset($status['color']) ? (string) $status['color'] : '';

                            if ($statusId === false || $statusId === null || $statusName === null || $statusSort === false || $statusSort === null) {
                                Logger::error('amoCRM status item missing required fields', ['pipeline_id' => $pipelineId, 'status' => $status]);
                                continue;
                            }

                            $statuses[] = [
                                'id' => $statusId,
                                'name' => $statusName,
                                'sort' => $statusSort,
                                'color' => $statusColor,
                            ];
                        }
                    }

                    usort($statuses, static function (array $a, array $b): int {
                        return $a['sort'] <=> $b['sort'];
                    });

                    $result[] = [
                        'id' => $pipelineId,
                        'name' => $pipelineName,
                        'sort' => $pipelineSort,
                        'color' => $pipelineColor,
                        'statuses' => $statuses,
                    ];
                }

                $hasNext = isset($response['_links']['next']['href']);
                if (!$hasNext) {
                    break;
                }

                $page++;
            }
        } catch (Throwable $e) {
            Logger::error('Failed to fetch amoCRM pipelines', ['page' => $page, 'error' => $e->getMessage()]);
            throw $e;
        }

        usort($result, static function (array $a, array $b): int {
            $sortCompare = $a['sort'] <=> $b['sort'];
            return $sortCompare !== 0 ? $sortCompare : ($a['id'] <=> $b['id']);
        });

        return $result;
    }
}
