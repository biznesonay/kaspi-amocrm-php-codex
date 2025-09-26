<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../lib/Db.php';
require_once __DIR__.'/../lib/Logger.php';

$code = $_GET['code'] ?? null;
$sub = env('AMO_SUBDOMAIN');
$cid = env('AMO_CLIENT_ID');
$sec = env('AMO_CLIENT_SECRET');
$redir = env('AMO_REDIRECT_URI');

if (!$code) { echo "Missing code"; exit; }

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
$codeHttp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($codeHttp >= 400) {
  echo "HTTP $codeHttp: $resp";
  exit;
}

$data = json_decode($resp, true);
$access = $data['access_token'] ?? '';
$refresh= $data['refresh_token'] ?? '';
$exp = time() + (int)($data['expires_in'] ?? 3600);

if (!$access || !$refresh) { echo "Invalid response"; exit; }

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
