<?php

namespace App\Jobs;

use App\Models\TelegramAccount;
use App\Services\WebSocketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use WebSocket\Client;
use WebSocket\ConnectionException;

class ListenToWebSocket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 0;
    public $tries = 1;

    public function __construct(
        private int $accountId
    ) {
        $this->onQueue('websocket');
    }

    public function handle(WebSocketService $ws): void
    {
        $account = TelegramAccount::find($this->accountId);

        if (!$account || !$account->hasContainer()) {
            Log::warning('Account not found or container not running', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        $wsUrl = $ws->getWebSocketUrl($account);

        Log::info('WebSocket listener started', [
            'account_id' => $account->id,
            'session_name' => $account->session_name,
            'ws_url' => $wsUrl,
        ]);

        $reconnectDelay = 1;
        $maxReconnectDelay = 60;

        while (true) {
            try {
                $client = new Client($wsUrl, [
                    'timeout' => 300,
                    'persistent' => true,
                    'fragment_size' => 4096,
                ]);

                Log::info('WebSocket connected', [
                    'session_name' => $account->session_name,
                ]);

                $reconnectDelay = 1;
                $lastCheck = time();

                while (true) {
                    // Проверяем статус контейнера каждые 30 секунд
                    if (time() - $lastCheck > 30) {
                        $account->refresh();
                        $lastCheck = time();

                        if (!$account->hasContainer()) {
                            Log::info('Container stopped, closing WebSocket', [
                                'session_name' => $account->session_name,
                            ]);
                            $client->close();
                            return;
                        }
                    }

                    try {
                        $message = $client->receive();

                        if ($message === null || $message === '') {
                            usleep(100000); // 100ms
                            continue;
                        }

                        // Декодируем JSON
                        $data = json_decode($message, true);

                        if (!$data || json_last_error() !== JSON_ERROR_NONE) {
                            Log::warning('Invalid JSON received', [
                                'session_name' => $account->session_name,
                                'message' => substr($message, 0, 200),
                                'error' => json_last_error_msg(),
                            ]);
                            continue;
                        }

                        // Проверяем, является ли это личным сообщением
                        if ($ws->isPrivateMessage($data)) {
                            $messageData = $ws->extractMessageData($data);

                            Log::info('Private message received', [
                                'session_name' => $account->session_name,
                                'from_id' => $messageData['from_id'],
                                'peer_id' => $messageData['peer_id'],
                                'message_preview' => substr($messageData['message'] ?? '', 0, 50),
                            ]);

                            // Отправляем на webhook
                            $ws->sendToWebhook($account->webhook_url, $messageData);
                        }

                    } catch (ConnectionException $e) {
                        Log::warning('WebSocket connection lost', [
                            'session_name' => $account->session_name,
                            'error' => $e->getMessage(),
                        ]);
                        break; // Выходим из внутреннего цикла для переподключения
                    } catch (\Exception $e) {
                        // Игнорируем таймауты чтения
                        if (strpos($e->getMessage(), 'timeout') !== false) {
                            Log::debug('WebSocket read timeout, continuing...', [
                                'session_name' => $account->session_name,
                            ]);
                            continue;
                        }

                        // Логируем другие ошибки, но продолжаем работу
                        Log::error('WebSocket message processing error', [
                            'session_name' => $account->session_name,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        continue;
                    }
                }

            } catch (\Exception $e) {
                Log::error('WebSocket connection error', [
                    'session_name' => $account->session_name,
                    'error' => $e->getMessage(),
                    'will_reconnect_in' => $reconnectDelay,
                ]);

                // Ждем перед переподключением
                sleep($reconnectDelay);
                $reconnectDelay = min($reconnectDelay * 2, $maxReconnectDelay);
            }
        }
    }
}
