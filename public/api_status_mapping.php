<?php
declare(strict_types=1);

require_once __DIR__.'/../config.php';
require_once __DIR__.'/../lib/Db.php';
require_once __DIR__.'/../lib/Logger.php';
require_once __DIR__.'/../lib/StatusMappingManager.php';
require_once __DIR__.'/../lib/AmoClient.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * Отправляет успешный JSON-ответ и завершает выполнение скрипта.
 *
 * @param mixed $data
 */
function respondSuccess(mixed $data): void {
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Отправляет ошибку в формате JSON и завершает выполнение скрипта.
 */
function respondError(string $message, int $status = 400, array $context = [], bool $log = true): void {
    if ($log) {
        Logger::error($message, $context);
    }
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$providedSecret = (string) ($_SERVER['HTTP_X_API_SECRET'] ?? '');
$expectedSecret = (string) env('CRON_SECRET', '');

if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    respondError('Forbidden', 403, ['reason' => 'invalid_secret'], true);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$manager = new StatusMappingManager();

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');
    if ($action === '') {
        respondError('Missing action', 400, ['method' => 'GET']);
    }

    try {
        switch ($action) {
            case 'get_mappings':
                $result = $manager->getAllMappings();
                break;
            case 'get_kaspi_statuses':
                $result = $manager->getKaspiStatuses();
                break;
            case 'get_stats':
                $result = $manager->getMappingStats();
                break;
            case 'get_amo_pipelines':
                $amoClient = new AmoClient();
                $result = $amoClient->getPipelines();
                break;
            default:
                respondError('Unknown action', 400, ['action' => $action, 'method' => 'GET']);
        }
        respondSuccess($result);
    } catch (Throwable $e) {
        Logger::error('Failed to handle GET action', [
            'action' => $action,
            'error' => $e->getMessage(),
        ]);
        respondError('Internal Server Error', 500, [], false);
    }
}

if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '[]', true);
    if (!is_array($payload)) {
        respondError('Invalid JSON body', 400, ['body' => $rawBody]);
    }

    $action = (string) ($payload['action'] ?? '');
    if ($action === '') {
        respondError('Missing action', 400, ['method' => 'POST']);
    }

    try {
        switch ($action) {
            case 'upsert_mapping':
                $kaspiStatus = isset($payload['kaspi_status']) ? trim((string) $payload['kaspi_status']) : '';
                $amoPipelineId = filter_var($payload['amo_pipeline_id'] ?? null, FILTER_VALIDATE_INT);
                $amoStatusId = filter_var($payload['amo_status_id'] ?? null, FILTER_VALIDATE_INT);
                $amoResponsible = $payload['amo_responsible_user_id'] ?? null;
                $amoResponsibleId = null;
                if ($amoResponsible !== null && $amoResponsible !== '') {
                    $filteredResponsible = filter_var($amoResponsible, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($filteredResponsible === false || $filteredResponsible === null) {
                        respondError('Invalid amo_responsible_user_id', 400, ['value' => $amoResponsible]);
                    }
                    $amoResponsibleId = (int) $filteredResponsible;
                }
                if ($kaspiStatus === '') {
                    respondError('kaspi_status is required', 400, ['action' => $action]);
                }
                if ($amoPipelineId === false || $amoPipelineId === null) {
                    respondError('amo_pipeline_id must be an integer', 400, ['value' => $payload['amo_pipeline_id'] ?? null]);
                }
                if ($amoStatusId === false || $amoStatusId === null) {
                    respondError('amo_status_id must be an integer', 400, ['value' => $payload['amo_status_id'] ?? null]);
                }

                $result = $manager->upsertMapping(
                    $kaspiStatus,
                    (int) $amoPipelineId,
                    (int) $amoStatusId,
                    $amoResponsibleId
                );
                break;
            case 'delete_mapping':
                $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
                if ($id === false || $id === null) {
                    respondError('id must be an integer', 400, ['action' => $action, 'value' => $payload['id'] ?? null]);
                }
                $result = $manager->deleteMapping((int) $id);
                break;
            case 'toggle_mapping':
                $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
                if ($id === false || $id === null) {
                    respondError('id must be an integer', 400, ['action' => $action, 'value' => $payload['id'] ?? null]);
                }
                $isActiveRaw = $payload['is_active'] ?? null;
                $isActive = filter_var($isActiveRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive === null) {
                    respondError('is_active must be boolean', 400, ['value' => $isActiveRaw]);
                }
                if ($isActive) {
                    $result = $manager->activateMapping((int) $id);
                } else {
                    $result = $manager->deactivateMapping((int) $id);
                }
                break;
            default:
                respondError('Unknown action', 400, ['action' => $action, 'method' => 'POST']);
        }
        respondSuccess($result);
    } catch (Throwable $e) {
        Logger::error('Failed to handle POST action', [
            'action' => $action,
            'error' => $e->getMessage(),
        ]);
        respondError('Internal Server Error', 500, [], false);
    }
}

respondError('Method Not Allowed', 405, ['method' => $method]);
