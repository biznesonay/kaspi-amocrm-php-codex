<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../lib/Db.php';
require_once __DIR__.'/../lib/Logger.php';
require_once __DIR__.'/../lib/AmoUtils.php';

$code = $_GET['code'] ?? null;
$rawSub = trim((string) env('AMO_SUBDOMAIN', ''));
$cid = trim((string) env('AMO_CLIENT_ID', ''));
$sec = trim((string) env('AMO_CLIENT_SECRET', ''));
$redir = trim((string) env('AMO_REDIRECT_URI', ''));

if (!$code) { echo "Missing code"; exit; }

$required = [
    'AMO_SUBDOMAIN' => $rawSub,
    'AMO_CLIENT_ID' => $cid,
    'AMO_CLIENT_SECRET' => $sec,
    'AMO_REDIRECT_URI' => $redir,
];
foreach ($required as $key => $value) {
    if ($value === '') {
        Logger::error('Missing amoCRM configuration value', ['key' => $key]);
        echo "Configuration error: environment variable {$key} is not set.";
        exit;
    }
}

$sub = normalizeAmoSubdomain($rawSub);
if ($sub === '') {
    Logger::error('Invalid AMO_SUBDOMAIN after normalisation', ['value' => $rawSub]);
    echo 'Configuration error: AMO_SUBDOMAIN is invalid after normalisation.';
    exit;
}

if (!preg_match('/^[a-zA-Z0-9-]+$/', $sub)) {
    Logger::error('AMO_SUBDOMAIN contains invalid characters', ['value' => $rawSub, 'normalized' => $sub]);
    echo 'Configuration error: AMO_SUBDOMAIN must contain only letters, digits, or hyphens.';
    exit;
}

$payload = json_encode([
  'client_id' => $cid,
  'client_secret' => $sec,
  'grant_type' => 'authorization_code',
  'code' => $code,
  'redirect_uri' => $redir,
], JSON_UNESCAPED_UNICODE);

$url = 'https://'.$sub.'.amocrm.ru/oauth2/access_token';
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
if ($resp === false) {
  $error = curl_error($ch);
  Logger::error('amoCRM OAuth cURL error', ['error' => $error]);
  echo 'Не удалось подключиться к amoCRM, проверьте доступность API.';
  curl_close($ch);
  exit;
}

if (!is_string($resp)) {
  curl_close($ch);
  Logger::error('amoCRM OAuth unexpected response type', ['type' => gettype($resp)]);
  echo 'Invalid response from amoCRM. Check logs for details.';
  exit;
}

$codeHttp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($codeHttp >= 400) {
  echo "HTTP $codeHttp: $resp";
  exit;
}

$data = json_decode($resp, true);
if (!is_array($data)) {
  Logger::error('amoCRM OAuth invalid JSON', ['http_code' => $codeHttp, 'body' => $resp]);
  echo "Invalid response from amoCRM. Check logs for details.";
  exit;
}

$access = $data['access_token'] ?? '';
$refresh= $data['refresh_token'] ?? '';
if (!$access || !$refresh) {
  Logger::error('amoCRM OAuth missing tokens', ['http_code' => $codeHttp, 'body' => $resp]);
  echo "Invalid response from amoCRM. Check logs for details.";
  exit;
}

$expiresIn = filter_var($data['expires_in'] ?? null, FILTER_VALIDATE_INT);
if ($expiresIn === false || $expiresIn === null) {
  Logger::error('amoCRM OAuth invalid expires_in', ['http_code' => $codeHttp, 'body' => $resp]);
  echo "Invalid response from amoCRM. Check logs for details.";
  exit;
}

$exp = time() + $expiresIn;

// store
$driver = env('DB_DRIVER', 'mysql');
if ($driver === 'pgsql') {
  $sql = "INSERT INTO oauth_tokens(service, access_token, refresh_token, expires_at) VALUES('amocrm', :a,:r,:e)
          ON CONFLICT (service) DO UPDATE SET access_token=EXCLUDED.access_token, refresh_token=EXCLUDED.refresh_token, expires_at=EXCLUDED.expires_at";
} else {
  $sql = "INSERT INTO oauth_tokens(service, access_token, refresh_token, expires_at) VALUES('amocrm', :a,:r,:e)
          ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), refresh_token=VALUES(refresh_token), expires_at=VALUES(expires_at)";
}
$stmt = Db::pdo()->prepare($sql);
$stmt->execute([':a'=>$access, ':r'=>$refresh, ':e'=>$exp]);

echo "Tokens saved. You can close this window.";
