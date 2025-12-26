<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Exceptions\TasApiException;

class TasApiService
{
    /**
     * Базовый запрос к TAS
     */
    private function request(
        int $port,
        string $endpoint,
        array $params = [],
        string $method = 'GET'
    ): array {
        $url = "http://127.0.0.1:{$port}{$endpoint}";

        try {

            $request = Http::timeout(30)
                ->withBasicAuth(
                    config('tas.api.username'),
                    config('tas.api.password')
                );

            if ($method === 'POST') {
                $response = $request->post($url, $params);
            } else {
                $response = $request->get($url, $params);
            }

            if (!$response->successful()) {
                throw new TasApiException(
                    "TAS API error [{$response->status()}]: {$response->body()}"
                );
            }

            $data = $response->json() ?? [];

            Log::debug('TAS API response', [
                'url' => $url,
                'response' => $data,
            ]);

            if (isset($data['success']) && $data['success'] === false) {
                $error = $data['errors'][0] ?? [];
                $errorMessage = $error['message'] ?? 'Unknown error';

                if (str_contains($errorMessage, 'FLOOD_WAIT')) {
                    preg_match('/FLOOD_WAIT_(\d+)/', $errorMessage, $matches);
                    $waitSeconds = $matches[1] ?? 0;
                    $waitHours = round($waitSeconds / 3600, 1);

                    throw new TasApiException(
                        "Telegram FLOOD_WAIT: Too many requests. Please wait {$waitHours} hours ({$waitSeconds} seconds) before trying again."
                    );
                }

                throw new TasApiException("TAS returned error: " . json_encode($error));
            }

            return $data;

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error('TAS API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new TasApiException("TAS API request failed: {$e->getMessage()}");
        }
    }

    /**
     * Сохранить настройки сессии (api_id, api_hash)
     */
    public function saveSessionSettings(
        int $port,
        string $session,
        string $apiId,
        string $apiHash
    ): void {
        $this->request($port, '/system/saveSessionSettings', [
            'session' => $session,
            'settings[app_info][api_id]' => $apiId,
            'settings[app_info][api_hash]' => $apiHash,
        ]);


    }

    /**
     * Установить webhook
     */
    public function setWebhook(int $port, string $webhookUrl): void
    {
        $this->request($port, '/api/setWebhook', [
            'url' => $webhookUrl,
        ]);

        Log::info('Webhook set', [
            'port' => $port,
            'webhook_url' => $webhookUrl,
        ]);
    }

    /**
     * Начать авторизацию по номеру телефона
     */
    public function phoneLogin(int $port, string $session, string $phone): void
    {
        $this->request($port, "/api/{$session}/phoneLogin", [
            'phone' => $phone,
        ]);

        Log::info('Phone login initiated', [
            'port' => $port,
            'session' => $session,
            'phone' => substr($phone, 0, 4) . '***',
        ]);
    }

    /**
     * Завершить авторизацию кодом
     */
    public function completePhoneLogin(int $port, string $session, string $code): array
    {
        $response = $this->request($port, "/api/{$session}/completePhoneLogin", [
            'code' => $code,
        ]);

        Log::info('Phone login completed', [
            'port' => $port,
            'session' => $session,
        ]);

        return $response;
    }

    /**
     * Завершить авторизацию 2FA
     */
    public function complete2faLogin(int $port, string $session, string $password): void
    {
        $this->request($port, "/api/{$session}/complete2faLogin", [
            'password' => $password,
        ]);

        Log::info('2FA login completed', [
            'port' => $port,
            'session' => $session,
        ]);
    }

    /**
     * Авторизация бота
     */
    public function botLogin(int $port, string $session, string $token): void
    {
        $this->request($port, "/api/{$session}/botLogin", [
            'token' => $token,
        ]);

        Log::info('Bot login completed', [
            'port' => $port,
            'session' => $session,
        ]);
    }

    /**
     * Получить информацию о текущем аккаунте
     */
    public function getSelf(int $port): array
    {
        return $this->request($port, '/api/getSelf');
    }

    public function getSessionList(int $port): array
    {
        return $this->request($port, '/system/getSessionList');
    }

    public function addSession(int $port, string $sessionName): void
    {
        $this->request($port, '/system/addSession', [
            'session' => $sessionName,
        ]);

        Log::info('Session added', [
            'port' => $port,
            'session' => $sessionName,
        ]);
    }

    public function sendMessage(
        int $port,
        string $peer,
        string $message,
        ?string $parseMode = null,
        ?string $sessionName = null
    ): array {
        $endpoint = $sessionName
            ? "/api/{$sessionName}/messages.sendMessage"
            : "/api/messages.sendMessage";

        $params = [
            'peer' => $peer,
            'message' => $message,
        ];

        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        $response = $this->request($port, $endpoint, $params, 'POST');

        return $response;
    }

    public function getHistory(
        int $port,
        string $peer,
        int $limit = 10,

        int $offset = 0
    ): array {
        return $this->request($port, '/api/messages.getHistory', [
            'peer' => $peer,
            'limit' => $limit,
            'offset_id' => $offset,
        ]);
    }

    /**
     * Получить информацию о пользователе/канале
     */
    public function getInfo(int $port, string $id): array
    {
        return $this->request($port, '/api/getInfo', [
            'id' => $id,
        ]);
    }

    public function sendDocument(
        int $port,
        string $peer,
        string $fileUrl,
        ?string $caption = null,
        ?string $parseMode = null,
        ?string $sessionName = null
    ): array {
        $endpoint = $sessionName
            ? "/api/{$sessionName}/messages.sendMedia"  // Используем sendMedia вместо sendDocument
            : "/api/messages.sendMedia";

        $params = [
            'peer' => $peer,
            'media' => [
                '_' => 'inputMediaUploadedDocument',
                'file' => $fileUrl,
                'attributes' => [
                    [
                        '_' => 'documentAttributeFilename',
                        'file_name' => basename($fileUrl)
                    ]
                ]
            ],
        ];

        if ($caption) {
            $params['message'] = $caption;
        }

        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        $response = $this->request($port, $endpoint, $params, 'POST');

        Log::info('Document sent', [
            'port' => $port,
            'peer' => $peer,
            'file_url' => $fileUrl,
            'message_id' => $response['response']['id'] ?? null,
        ]);

        return $response;
    }
    public function sendVoice(
        int $port,
        string $peer,
        string $voiceUrl,
        ?string $caption = null,
        ?string $parseMode = null,
        ?string $sessionName = null
    ): array {
        // Конвертируем аудио файл в OGG формат для голосовых сообщений
        $convertedUrl = $this->convertAudioToOgg($voiceUrl);
        
        if (!$convertedUrl) {
            throw new TasApiException("Failed to convert audio file to OGG format");
        }

        // Используем тот же подход что и sendDocument - через messages.sendMedia
        // TAS API преобразует строку URL в RemoteUrl автоматически
        $endpoint = $sessionName
            ? "/api/{$sessionName}/messages.sendMedia"
            : "/api/messages.sendMedia";

        $params = [
            'peer' => $peer,
            'media' => [
                '_' => 'inputMediaUploadedDocument',
                'file' => $convertedUrl,  // TAS API преобразует строку в RemoteUrl
                'mime_type' => 'audio/ogg',
                'attributes' => [
                    [
                        '_' => 'documentAttributeAudio',
                        'voice' => true,
                    ],
                ],
            ],
        ];

        if ($caption) {
            $params['message'] = $caption;
        }

        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        Log::info('Sending voice message', [
            'port' => $port,
            'peer' => $peer,
            'original_url' => $voiceUrl,
            'converted_url' => $convertedUrl,
            'method' => 'messages.sendMedia with voice attribute',
        ]);

        $response = $this->request($port, $endpoint, $params, 'POST');

        Log::info('Voice sent', [
            'port' => $port,
            'peer' => $peer,
            'message_id' => $response['response']['id'] ?? null,
        ]);

        return $response;
    }

    /**
     * Конвертирует аудио файл в OGG формат через внешний сервис
     */
    private function convertAudioToOgg(string $fileUrl): ?string
    {
        try {
            $converterUrl = config('services.audio_converter.url');
            $targetFormat = config('services.audio_converter.target_format', 'ogg');

            if (!$converterUrl) {
                Log::warning('Audio converter URL not configured, using original file');
                return $fileUrl;
            }

            Log::info('Converting audio file', [
                'original_url' => $fileUrl,
                'target_format' => $targetFormat,
            ]);

            // Скачиваем файл
            $fileContent = file_get_contents($fileUrl);
            if ($fileContent === false) {
                Log::error('Failed to download audio file', ['url' => $fileUrl]);
                return null;
            }

            // Создаём временный файл
            $tempFile = tempnam(sys_get_temp_dir(), 'voice_');
            file_put_contents($tempFile, $fileContent);

            try {
                // Открываем файл для чтения
                $fileStream = fopen($tempFile, 'r');
                if ($fileStream === false) {
                    Log::error('Failed to open temporary file', ['path' => $tempFile]);
                    return null;
                }

                try {
                    // Отправляем на конвертацию
                    $convertResponse = Http::timeout(120)
                        ->attach('file', $fileStream, basename($fileUrl))
                        ->attach('target_format', $targetFormat)
                        ->post($converterUrl);
                } finally {
                    if (is_resource($fileStream)) {
                        fclose($fileStream);
                    }
                }

                if (!$convertResponse->successful()) {
                    Log::error('Audio conversion failed', [
                        'status' => $convertResponse->status(),
                        'body' => $convertResponse->body(),
                    ]);
                    return null;
                }

                $convertData = $convertResponse->json();
                $downloadUrl = $convertData['download_url'] ?? null;

                if (($convertData['status'] ?? '') !== 'ok' || !$downloadUrl) {
                    Log::error('Invalid conversion response', ['response' => $convertData]);
                    return null;
                }

                Log::info('Audio converted successfully', [
                    'original_url' => $fileUrl,
                    'converted_url' => $downloadUrl,
                ]);

                return $downloadUrl;

            } finally {
                // Удаляем временный файл
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }

        } catch (\Throwable $e) {
            Log::error('Audio conversion exception', [
                'error' => $e->getMessage(),
                'url' => $fileUrl,
            ]);
            return null;
        }
    }

    public function sendPhoto(
        int $port,
        string $peer,
        string $photoUrl,
        ?string $caption = null,
        ?string $parseMode = null,
        ?string $sessionName = null
    ): array {
        $endpoint = $sessionName
            ? "/api/{$sessionName}/sendPhoto"
            : "/api/sendPhoto";

        $params = [
            'peer' => $peer,
            'file' => $photoUrl,
        ];

        if ($caption) {
            $params['caption'] = $caption;
        }

        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        $response = $this->request($port, $endpoint, $params, 'POST');

        Log::info('Photo sent', [
            'port' => $port,
            'peer' => $peer,
            'message_id' => $response['response']['id'] ?? null,
        ]);

        return $response;
    }


}
//123123123
