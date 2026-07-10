<?php

declare(strict_types=1);

require_once __DIR__ . '/src/BitrixClient.php';
require_once __DIR__ . '/src/UserFieldMigrator.php';

header('Content-Type: application/json; charset=utf-8');

const CONFIG_FILE = __DIR__ . '/config.json';

function jsonOut(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function loadConfig(): array
{
    if (!file_exists(CONFIG_FILE)) {
        return [];
    }
    $config = json_decode((string) file_get_contents(CONFIG_FILE), true);

    return is_array($config) ? $config : [];
}

function saveConfig(array $config): void
{
    file_put_contents(
        CONFIG_FILE,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function requireClients(array $config): array
{
    if (empty($config['sourceWebhook']) || empty($config['targetWebhook'])) {
        jsonOut(['ok' => false, 'error' => 'Сначала заполните и сохраните URL вебхуков обоих порталов в настройках'], 400);
    }

    return [
        new BitrixClient($config['sourceWebhook']),
        new BitrixClient($config['targetWebhook']),
    ];
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? $input['action'] ?? '';

try {
    switch ($action) {
        case 'get_config':
            $config = loadConfig();
            jsonOut([
                'ok' => true,
                'config' => [
                    'sourceWebhook' => $config['sourceWebhook'] ?? '',
                    'targetWebhook' => $config['targetWebhook'] ?? '',
                ],
                'entities' => array_map(
                    static fn(array $e): string => $e['title'],
                    UserFieldMigrator::ENTITIES
                ),
            ]);
            // no break — jsonOut завершает выполнение

        case 'save_config':
            $source = trim((string) ($input['sourceWebhook'] ?? ''));
            $target = trim((string) ($input['targetWebhook'] ?? ''));
            foreach (['источника' => $source, 'приёмника' => $target] as $label => $url) {
                if ($url !== '' && !preg_match('~^https?://[^/]+/rest/\d+/[^/]+/?$~', $url)) {
                    jsonOut([
                        'ok' => false,
                        'error' => 'URL вебхука ' . $label . ' должен иметь вид https://portal.bitrix24.ru/rest/1/код/',
                    ], 400);
                }
            }
            saveConfig(['sourceWebhook' => $source, 'targetWebhook' => $target]);
            jsonOut(['ok' => true]);
            // no break

        case 'test_connection':
            $config = loadConfig();
            $which = $input['which'] ?? '';
            $url = $which === 'source' ? ($config['sourceWebhook'] ?? '') : ($config['targetWebhook'] ?? '');
            if ($url === '') {
                jsonOut(['ok' => false, 'error' => 'URL вебхука не задан — сохраните настройки'], 400);
            }
            $client = new BitrixClient($url);
            jsonOut(['ok' => true, 'user' => $client->testConnection()]);
            // no break

        case 'load_fields':
            $entity = (string) ($input['entity'] ?? '');
            if (!isset(UserFieldMigrator::ENTITIES[$entity])) {
                jsonOut(['ok' => false, 'error' => 'Неизвестная сущность'], 400);
            }
            [$source, $target] = requireClients(loadConfig());
            $migrator = new UserFieldMigrator($source, $target);
            jsonOut(['ok' => true] + $migrator->listFields($entity));
            // no break

        case 'migrate':
            $entity = (string) ($input['entity'] ?? '');
            $fields = $input['fields'] ?? [];
            $updateExisting = (bool) ($input['updateExisting'] ?? false);
            if (!isset(UserFieldMigrator::ENTITIES[$entity])) {
                jsonOut(['ok' => false, 'error' => 'Неизвестная сущность'], 400);
            }
            if (!is_array($fields) || $fields === []) {
                jsonOut(['ok' => false, 'error' => 'Не выбрано ни одного поля'], 400);
            }
            set_time_limit(0);
            [$source, $target] = requireClients(loadConfig());
            $migrator = new UserFieldMigrator($source, $target);
            jsonOut(['ok' => true, 'results' => $migrator->migrate($entity, array_map('strval', $fields), $updateExisting)]);
            // no break

        default:
            jsonOut(['ok' => false, 'error' => 'Неизвестное действие'], 400);
    }
} catch (BitrixApiException $e) {
    jsonOut(['ok' => false, 'error' => 'Битрикс24: ' . $e->getMessage() . ' [' . $e->errorCode . ']'], 502);
} catch (Throwable $e) {
    jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
}
