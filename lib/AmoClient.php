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
}
