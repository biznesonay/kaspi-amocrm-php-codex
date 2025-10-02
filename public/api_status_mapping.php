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
        respondError('Missing action query parameter', 400, ['method' => 'GET']);
    }

    try {
        switch ($action) {
            case 'get_mappings':
                $kaspiStatus = isset($_GET['kaspi_status']) ? trim((string) $_GET['kaspi_status']) : '';
                $amoPipelineIdRaw = $_GET['amo_pipeline_id'] ?? null;
                $onlyActiveRaw = $_GET['only_active'] ?? null;
                $onlyActive = null;
                if ($onlyActiveRaw !== null) {
                    $onlyActive = filter_var($onlyActiveRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                }
                if ($kaspiStatus !== '' && $amoPipelineIdRaw !== null) {
                    $amoPipelineId = filter_var($amoPipelineIdRaw, FILTER_VALIDATE_INT);
                    if ($amoPipelineId === false || $amoPipelineId === null) {
                        respondError('amo_pipeline_id must be an integer', 400, ['value' => $amoPipelineIdRaw]);
                    }
                    $result = $manager->getMappings(
                        $kaspiStatus,
                        (int) $amoPipelineId,
                        $onlyActive ?? false
                    );
                } else {
                    $result = $manager->getAllMappings();
                }
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
            case 'get_amo_status_ids':
                $kaspiStatus = isset($_GET['kaspi_status']) ? trim((string) $_GET['kaspi_status']) : '';
                $amoPipelineIdRaw = $_GET['amo_pipeline_id'] ?? null;
                if ($kaspiStatus === '' || $amoPipelineIdRaw === null) {
                    respondError('kaspi_status and amo_pipeline_id are required', 400, ['action' => $action]);
                }
                $amoPipelineId = filter_var($amoPipelineIdRaw, FILTER_VALIDATE_INT);
                if ($amoPipelineId === false || $amoPipelineId === null) {
                    respondError('amo_pipeline_id must be an integer', 400, ['value' => $amoPipelineIdRaw]);
                }
                $amoStatusId = $manager->getAmoStatusId($kaspiStatus, (int) $amoPipelineId);
                $result = [
                    'amo_status_id' => $amoStatusId,
                    'amo_status_ids' => $amoStatusId !== null ? [$amoStatusId] : [],
                ];
                break;
            default:
                respondError('Unknown action query parameter value', 400, ['action' => $action, 'method' => 'GET']);
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
    $action = (string) ($_GET['action'] ?? '');
    if ($action === '') {
        respondError('Missing action query parameter', 400, ['method' => 'POST']);
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '[]', true);
    if (!is_array($payload)) {
        respondError('Invalid JSON body', 400, ['body' => $rawBody]);
    }

    try {
        switch ($action) {
            case 'upsert_mapping':
                $kaspiStatus = isset($payload['kaspi_status']) ? trim((string) $payload['kaspi_status']) : '';
                $amoPipelineId = filter_var($payload['amo_pipeline_id'] ?? null, FILTER_VALIDATE_INT);
                $amoStatusIdRaw = $payload['amo_status_id'] ?? null;
                if ($amoStatusIdRaw === null && array_key_exists('amo_status_ids', $payload)) {
                    $deprecatedAmoStatusIds = $payload['amo_status_ids'];
                    if (is_array($deprecatedAmoStatusIds)) {
                        if ($deprecatedAmoStatusIds === []) {
                            respondError(
                                'amo_status_ids array must contain at least one value',
                                400,
                                [
                                    'action' => $action,
                                    'value' => $deprecatedAmoStatusIds,
                                ]
                            );
                        }
                        $amoStatusIdRaw = reset($deprecatedAmoStatusIds);
                    } else {
                        $amoStatusIdRaw = $deprecatedAmoStatusIds;
                    }
                    Logger::info('amo_status_ids array received, using first value for backward compatibility', [
                        'action' => $action,
                        'amo_status_ids' => $deprecatedAmoStatusIds,
                    ]);
                }
                $isActiveRaw = $payload['is_active'] ?? true;
                $isActive = filter_var($isActiveRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive === null) {
                    respondError('is_active must be a boolean value', 400, ['value' => $isActiveRaw]);
                }
                if ($kaspiStatus === '') {
                    respondError('kaspi_status is required', 400, ['action' => $action]);
                }
                if ($amoPipelineId === false || $amoPipelineId === null) {
                    respondError('amo_pipeline_id must be an integer', 400, ['value' => $payload['amo_pipeline_id'] ?? null]);
                }
                if ($amoStatusIdRaw === null) {
                    respondError('amo_status_id is required', 400, ['action' => $action]);
                }
                $statusId = filter_var($amoStatusIdRaw, FILTER_VALIDATE_INT);
                if ($statusId === false || $statusId === null) {
                    respondError('amo_status_id must be an integer value', 400, ['value' => $amoStatusIdRaw]);
                }
                $amoStatusId = (int) $statusId;

                $id = $manager->upsertMapping(
                    $kaspiStatus,
                    (int) $amoPipelineId,
                    $amoStatusId,
                    (bool) $isActive
                );
                $result = ['id' => $id];
                break;
            case 'delete_mapping':
                $idRaw = $_GET['id'] ?? null;
                $id = filter_var($idRaw, FILTER_VALIDATE_INT);
                if ($id === false || $id === null) {
                    respondError('id query parameter must be an integer', 400, ['action' => $action, 'value' => $idRaw]);
                }
                $result = $manager->deleteMapping((int) $id);
                break;
            case 'toggle_mapping':
                $idRaw = $payload['id'] ?? null;
                $id = filter_var($idRaw, FILTER_VALIDATE_INT);
                if ($id === false || $id === null) {
                    respondError('id in request body must be an integer', 400, ['action' => $action, 'value' => $idRaw]);
                }
                $isActiveRaw = $payload['is_active'] ?? null;
                $isActive = filter_var($isActiveRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive === null) {
                    respondError('is_active must be a boolean value', 400, ['value' => $isActiveRaw]);
                }
                if ($isActive) {
                    $result = $manager->activateMapping((int) $id);
                } else {
                    $result = $manager->deactivateMapping((int) $id);
                }
                break;
            default:
                respondError('Unknown action query parameter value', 400, ['action' => $action, 'method' => 'POST']);
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
