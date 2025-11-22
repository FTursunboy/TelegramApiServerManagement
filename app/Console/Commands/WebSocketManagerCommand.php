<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\WebSocketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Amp\Websocket\Client\WebsocketHandshake;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;
use function Amp\Websocket\Client\connect;

class WebSocketManagerCommand extends Command
{
    protected $signature = 'websocket:manager';
    protected $description = 'Manage WebSocket connections for all Telegram accounts';

    private array $connections = [];
    private array $connectionIds = []; // Store unique ID for each connection

    public function handle(WebSocketService $ws): int
    {
        async(function () use ($ws) {
            while (true) {
                $this->syncConnections($ws);
                delay(10);
            }
        });

        EventLoop::run();

        return 0;
    }

    private function syncConnections(WebSocketService $ws): void
    {
        $activeAccounts = TelegramAccount::whereNotNull('container_name')
            ->whereNotNull('container_port')
            ->whereIn('status', ['ready', 'waiting_code', 'waiting_2fa'])
            ->get();

        foreach ($activeAccounts as $account) {
            $key = "account_{$account->id}";

            if (Cache::pull("reconnect_ws_{$account->id}")) {
                $this->info("Reconnect signal received for account {$account->id}");
                unset($this->connections[$key]);
                unset($this->connectionIds[$key]);
            }

            if (!isset($this->connections[$key])) {
                $this->startListening($account, $ws);
            }
        }

        foreach (array_keys($this->connections) as $key) {
            $accountId = (int) str_replace('account_', '', $key);
            if (!$activeAccounts->contains('id', $accountId)) {
                unset($this->connections[$key]);
                $this->info("Removed connection for account {$accountId}");
            }
        }
    }

    private function startListening(TelegramAccount $account, WebSocketService $ws): void
    {
        $key = "account_{$account->id}";
        $wsUrl = $ws->getWebSocketUrl($account);

        $this->connections[$key] = true;

        async(function () use ($account, $ws, $wsUrl, $key) {
            $reconnectDelay = 1;
            $maxReconnectDelay = 60;

            while (isset($this->connections[$key])) {
                try {
                    $handshake = new WebsocketHandshake($wsUrl);
                    $connection = connect($handshake);

                    $this->info("WebSocket connected: {$account->session_name}");
                    Log::info('WebSocket connected', [
                        'session_name' => $account->session_name,
                        'ws_url' => $wsUrl,
                    ]);

                    $reconnectDelay = 1;

                    while ($message = $connection->receive()) {
                        if (Cache::has("reconnect_ws_{$account->id}")) {
                            $this->info("Reconnecting WebSocket for {$account->session_name}");
                            $connection->close();
                            break;
                        }

                        if (!isset($this->connections[$key])) {
                            $connection->close();
                            return;
                        }

                        $payload = $message->buffer();
                        $data = json_decode($payload, true);

                        if (!$data || json_last_error() !== JSON_ERROR_NONE) {
                            continue;
                        }

                        if ($ws->isPrivateMessage($data)) {
                            $messageData = $ws->extractMessageData($data);

                            $this->line(sprintf(
                                '[%s] Private message: %s -> %s',
                                $account->session_name,
                                $messageData['from_id'],
                                substr($messageData['message'] ?? '', 0, 30)
                            ));

                            $account->refresh();
                            $ws->sendToWebhook($account->webhook_url, $messageData);
                        }
                    }

                    if ($connection->isClosed()) {
                        $this->warn("WebSocket closed: {$account->session_name}");
                    }

                } catch (\Throwable $e) {
                    if (!isset($this->connections[$key])) {
                        return;
                    }

                    $this->warn("WebSocket error for {$account->session_name}: {$e->getMessage()}");

                    delay($reconnectDelay);
                    $reconnectDelay = min($reconnectDelay * 2, $maxReconnectDelay);
                }
            }
        });

        $this->info("Started listener for: {$account->session_name}");
        Log::info('Started WebSocket listener', [
            'session_name' => $account->session_name,
        ]);
    }
}

