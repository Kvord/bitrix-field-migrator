<?php

declare(strict_types=1);

class BitrixApiException extends RuntimeException
{
    public string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}

/**
 * Клиент REST API Битрикс24, работающий через входящий вебхук.
 * URL вебхука имеет вид: https://portal.bitrix24.ru/rest/1/xxxxxxxxxxxx/
 */
class BitrixClient
{
    private string $webhookUrl;

    /** Пауза между запросами (мкс), чтобы не упереться в лимит 2 запроса/сек */
    private int $throttleUs;

    private float $lastCallAt = 0.0;

    public function __construct(string $webhookUrl, int $throttleUs = 550000)
    {
        $webhookUrl = trim($webhookUrl);
        if ($webhookUrl === '' || !preg_match('~^https?://~i', $webhookUrl)) {
            throw new InvalidArgumentException('Некорректный URL вебхука: ' . $webhookUrl);
        }
        $this->webhookUrl = rtrim($webhookUrl, '/') . '/';
        $this->throttleUs = $throttleUs;
    }

    /**
     * Вызов метода REST API. Возвращает полный ответ (с ключами result, total, next...).
     */
    public function call(string $method, array $params = []): array
    {
        $this->throttle();

        $ch = curl_init($this->webhookUrl . $method . '.json');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new BitrixApiException('NETWORK_ERROR', 'Ошибка сети при вызове ' . $method . ': ' . $curlError);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new BitrixApiException(
                'BAD_RESPONSE',
                'Некорректный ответ портала (HTTP ' . $httpCode . ') при вызове ' . $method
            );
        }

        if (isset($data['error'])) {
            throw new BitrixApiException(
                (string) $data['error'],
                (string) ($data['error_description'] ?? $data['error'])
            );
        }

        return $data;
    }

    /**
     * Вызов списочного метода с обходом всех страниц пагинации.
     */
    public function callAll(string $method, array $params = []): array
    {
        $items = [];
        $start = 0;
        do {
            $params['start'] = $start;
            $response = $this->call($method, $params);
            $result = $response['result'] ?? [];
            if (is_array($result)) {
                foreach ($result as $item) {
                    $items[] = $item;
                }
            }
            $start = $response['next'] ?? null;
        } while ($start !== null);

        return $items;
    }

    /**
     * Проверка соединения. Возвращает имя пользователя вебхука.
     */
    public function testConnection(): string
    {
        $response = $this->call('profile');
        $profile = $response['result'] ?? [];
        $name = trim(($profile['NAME'] ?? '') . ' ' . ($profile['LAST_NAME'] ?? ''));

        return $name !== '' ? $name : 'ID ' . ($profile['ID'] ?? '?');
    }

    private function throttle(): void
    {
        $now = microtime(true);
        $elapsedUs = (int) (($now - $this->lastCallAt) * 1000000);
        if ($this->lastCallAt > 0 && $elapsedUs < $this->throttleUs) {
            usleep($this->throttleUs - $elapsedUs);
        }
        $this->lastCallAt = microtime(true);
    }
}
