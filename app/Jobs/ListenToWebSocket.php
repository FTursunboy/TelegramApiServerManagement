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
                            usleep(100000);
                            continue;
                        }

                        $update = json_decode($message, true);

                        if (!$update) {
                            continue;
                        }

                        if ($ws->isPrivateMessage($update)) {
                            $data = $ws->extractMessageData($update);

                            Log::info('Private message received', [
                                'session_name' => $account->session_name,
                                'from_id' => $data['from_id'],
                                'message_preview' => substr($data['message'] ?? '', 0, 50),
                            ]);

                            $ws->sendToWebhook($account->webhook_url, $data);
                        }

                    } catch (ConnectionException $e) {
                        Log::warning('WebSocket connection lost', [
                            'session_name' => $account->session_name,
                            'error' => $e->getMessage(),
                        ]);
                        break;
                    } catch (\Exception $e) {
                        if (strpos($e->getMessage(), 'timeout') !== false) {
                            Log::debug('WebSocket read timeout, continuing...', [
                                'session_name' => $account->session_name,
                            ]);
                            continue;
                        }
                        throw $e;
                    }
                }

            } catch (\Exception $e) {
                Log::error('WebSocket error', [
                    'session_name' => $account->session_name,
                    'error' => $e->getMessage(),
                ]);

                sleep($reconnectDelay);
                $reconnectDelay = min($reconnectDelay * 2, $maxReconnectDelay);
            }
        }
    }
}

