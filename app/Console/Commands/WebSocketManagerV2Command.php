<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\WebSocketServiceV2;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Client\WebsocketConnection;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;
use function Amp\Websocket\Client\connect;


class WebSocketManagerV2Command extends Command
{
    protected $signature = 'websocket:manager-v2 {--debug : Enable debug output}';
    protected $description = 'Manage WebSocket connections V2 (improved version)';

    // Connection tracking
    private array $connections = [];
    private array $activeFibers = [];

    private array $processedMessages = [];
    private const DEDUP_CACHE_SIZE = 1000;
    private const DEDUP_TTL = 60;

    private int $totalEventsReceived = 0;
    private int $totalWebhooksSent = 0;
    private int $totalReconnects = 0;
    private int $totalErrors = 0;
    private int $totalDuplicatesSkipped = 0;

    private bool $shouldStop = false;
    private int $lastStatsLog = 0;

    private const SYNC_INTERVAL = 10;
    private const PING_INTERVAL = 10;
    private const STATS_INTERVAL = 300;
    private const MAX_RECONNECT_DELAY = 30;
    private const INITIAL_RECONNECT_DELAY = 1;
    private const BACKPRESSURE_LIMIT = 50;

    public function handle(WebSocketServiceV2 $ws): int
    {
        $this->info('🚀 WebSocket Manager V2 starting...');

        EventLoop::setErrorHandler(function (\Throwable $e) {
            $this->error("EventLoop error: {$e->getMessage()}");
            Log::error('EventLoop uncaught error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        });

        $this->setupShutdownHandlers();

        async(function () use ($ws) {
            while (!$this->shouldStop) {
                try {
                    $this->syncConnections($ws);
                    $this->logStatsIfNeeded();
                } catch (\Throwable $e) {
                    $this->error("Sync error: {$e->getMessage()}");
                    Log::error('Sync connections error', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                delay(self::SYNC_INTERVAL);
            }

            $this->info('Main loop stopped, cleaning up...');
            $this->cleanup();
        });

        EventLoop::run();

        $this->info('✅ WebSocket Manager V2 stopped gracefully');
        return 0;
    }


    private function setupShutdownHandlers(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () {
                $this->warn('⚠️  Received SIGTERM, shutting down gracefully...');
                $this->shouldStop = true;
            });

            pcntl_signal(SIGINT, function () {
                $this->warn('⚠️  Received SIGINT, shutting down gracefully...');
                $this->shouldStop = true;
            });

            $this->info('✅ Graceful shutdown handlers registered');
        } else {
            $this->warn('⚠️  pcntl extension not available, graceful shutdown disabled');
        }
    }


    private function syncConnections(WebSocketServiceV2 $ws): void
    {
        $activeAccounts = TelegramAccount::whereNotNull('container_name')
            ->whereNotNull('container_port')
            ->whereIn('status', ['ready', 'waiting_code', 'waiting_2fa'])
            ->select(['id', 'session_name', 'container_port', 'webhook_url'])
            ->get();

        foreach ($activeAccounts as $account) {
            $key = "account_{$account->id}";

            if (Cache::has("reconnect_ws_{$account->id}")) {
                Cache::forget("reconnect_ws_{$account->id}");
                $this->info("🔄 Reconnect signal for account {$account->id}");
                $this->stopConnection($key);
                delay(0.5);
            }

            if (!isset($this->connections[$key])) {
                $this->startListening($account, $ws);
            }
        }

        $activeIds = $activeAccounts->pluck('id')->map(fn($id) => "account_{$id}")->toArray();
        foreach (array_keys($this->connections) as $key) {
            if (!in_array($key, $activeIds)) {
                $this->stopConnection($key);
            }
        }
    }

    private function startListening(TelegramAccount $account, WebSocketServiceV2 $ws): void
    {
        $key = "account_{$account->id}";
        $fiberId = uniqid('fiber_', true);
        $wsUrl = $ws->getWebSocketUrl($account);

        $webhookUrl = $account->webhook_url;
        $sessionName = $account->session_name;
        $accountId = $account->id;

        $this->connections[$key] = [
            'fiber_id' => $fiberId,
            'account_id' => $accountId,
            'session_name' => $sessionName,
            'started_at' => time(),
            'pending_webhooks' => 0,
        ];

        $this->activeFibers[$fiberId] = [
            'key' => $key,
            'account_id' => $accountId,
            'started_at' => time(),
            'reconnects' => 0,
            'events_received' => 0,
        ];

        async(function () use ($fiberId, $key, $wsUrl, $webhookUrl, $sessionName, $ws) {
            $reconnectDelay = self::INITIAL_RECONNECT_DELAY;

            while (isset($this->connections[$key]) && !$this->shouldStop) {
                $connection = null;
                $pingLoopId = null;

                try {
                    $handshake = new WebsocketHandshake($wsUrl);
                    $connection = connect($handshake);

                    $this->info("✅ Connected: {$sessionName} (fiber: {$fiberId})");
                    Log::info('WebSocket connected', [
                        'session' => $sessionName,
                        'fiber_id' => $fiberId,
                    ]);

                    $reconnectDelay = self::INITIAL_RECONNECT_DELAY;

                    $pingLoopId = $this->setupPingLoop($connection, $sessionName);

                    $this->setupCloseHandler($connection, $key, $fiberId, $pingLoopId);

                    while ($message = $connection->receive()) {
                        if ($this->shouldStop || !isset($this->connections[$key])) {
                            $this->info("Stopping listener for {$sessionName}");
                            $connection->close();
                            break;
                        }

                        $this->processMessage($message, $key, $fiberId, $webhookUrl, $ws);
                    }

                    if ($connection->isClosed()) {
                        $reason = $connection->getCloseInfo()?->getReason() ?? 'unknown';
                        $this->warn("Connection closed: {$sessionName} - {$reason}");
                    }

                } catch (\Throwable $e) {
                    if (!isset($this->connections[$key]) || $this->shouldStop) {
                        break;
                    }

                    $this->totalErrors++;
                    $this->warn("WebSocket error for {$sessionName}: {$e->getMessage()}");

                    Log::warning('WebSocket error', [
                        'session' => $sessionName,
                        'error' => $e->getMessage(),
                        'fiber_id' => $fiberId,
                    ]);

                    if ($pingLoopId) {
                        EventLoop::cancel($pingLoopId);
                    }
                    if ($connection && !$connection->isClosed()) {
                        try {
                            $connection->close();
                        } catch (\Throwable) {
                        }
                    }

                    $this->totalReconnects++;
                    if (isset($this->activeFibers[$fiberId])) {
                        $this->activeFibers[$fiberId]['reconnects']++;
                    }

                    delay($reconnectDelay);
                    $reconnectDelay = min($reconnectDelay * 2, self::MAX_RECONNECT_DELAY);
                }
            }

            $this->cleanupFiber($fiberId, $key);
        });

    }

    private function setupPingLoop(WebsocketConnection $connection, string $sessionName): string
    {
        return EventLoop::repeat(self::PING_INTERVAL, function () use ($connection, $sessionName) {
            if (!$connection->isClosed()) {
                try {
                    $connection->ping();
                } catch (\Throwable $e) {
                    Log::debug('Ping failed', [
                        'session' => $sessionName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }


    private function setupCloseHandler(
        WebsocketConnection $connection,
        string              $key,
        string              $fiberId,
        ?string             $pingLoopId
    ): void
    {
        $connection->onClose(function () use ($key, $fiberId, $pingLoopId) {
            if ($pingLoopId) {
                EventLoop::cancel($pingLoopId);
            }

            if (isset($this->connections[$key])) {
                Log::debug('Connection closed gracefully', [
                    'key' => $key,
                    'fiber_id' => $fiberId,
                ]);
            }
        });
    }


    private function processMessage(
        $message,
        string $key,
        string $fiberId,
        string $webhookUrl,
        WebSocketServiceV2 $ws
    ): void
    {
        try {
            $payload = $message->buffer();
            $data = json_decode($payload, true);

            $this->totalEventsReceived++;
            if (isset($this->activeFibers[$fiberId])) {
                $this->activeFibers[$fiberId]['events_received']++;
            }

            $update = $data['result']['update'] ?? [];
            $message = $update['message'] ?? [];

            Log::debug('WebSocket event received', [
                'fiber_id' => substr($fiberId, 0, 12),
                'event_type' => $update['_'] ?? 'unknown',
                'from_id' => $message['from_id'] ?? null,
                'peer_id' => $message['peer_id'] ?? null,
                'out' => $message['out'] ?? false,
                'message_text' => substr($message['message'] ?? '', 0, 50),
            ]);

            if (!$ws->isPrivateMessage($data)) {
                Log::debug('Event filtered out (not private message)', [
                    'event_type' => $update['_'] ?? 'unknown',
                    'from_id' => $message['from_id'] ?? null,
                    'peer_id' => $message['peer_id'] ?? null,
                ]);
                return;
            }

            $messageData = $ws->extractMessageData($data);

            $messageId = $message['id'] ?? null;
            $sessionName = $messageData['session'] ?? $key;
            $dedupKey = "{$sessionName}_{$messageId}";

            if ($messageId && $this->isDuplicateMessage($dedupKey)) {
                $this->totalDuplicatesSkipped++;
                return;
            }

            $this->sendToWebhookAsync($key, $webhookUrl, $messageData, $ws);

        } catch (\Throwable $e) {
            $this->totalErrors++;
            Log::error('Message processing error', [
                'fiber_id' => $fiberId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendToWebhookAsync(
        string             $key,
        string             $webhookUrl,
        array              $messageData,
        WebSocketServiceV2 $ws
    ): void
    {
        if (!isset($this->connections[$key])) {
            return;
        }

        $pending = $this->connections[$key]['pending_webhooks'] ?? 0;
        if ($pending >= self::BACKPRESSURE_LIMIT) {

            return;
        }

        $this->connections[$key]['pending_webhooks'] = $pending + 1;

        async(function () use ($key, $webhookUrl, $messageData, $ws) {
            try {
                $ws->sendToWebhook($webhookUrl, $messageData);
                $this->totalWebhooksSent++;
            } catch (\Throwable $e) {
                $this->totalErrors++;

            } finally {
                if (isset($this->connections[$key])) {
                    $this->connections[$key]['pending_webhooks']--;
                }
            }
        })->ignore();
    }

    /**
     * Stop a connection
     */
    private function stopConnection(string $key): void
    {
        if (!isset($this->connections[$key])) {
            return;
        }

        $this->info("🛑 Stopping connection: {$key}");

        unset($this->connections[$key]);
    }

    /**
     * Cleanup fiber resources
     */
    private function cleanupFiber(string $fiberId, string $key): void
    {
        unset($this->activeFibers[$fiberId]);
        unset($this->connections[$key]);

        Log::info('Fiber terminated', [
            'fiber_id' => $fiberId,
            'key' => $key,
        ]);
    }


    private function isDuplicateMessage(string $dedupKey): bool
    {
        $now = time();

        if (isset($this->processedMessages[$dedupKey])) {
            $timestamp = $this->processedMessages[$dedupKey];
            if (($now - $timestamp) < self::DEDUP_TTL) {
                return true;
            }
        }

        $this->processedMessages[$dedupKey] = $now;

        if (count($this->processedMessages) > self::DEDUP_CACHE_SIZE) {
            $this->cleanupDedupCache();
        }

        return false;
    }

    /**
     * Remove expired entries from dedup cache
     */
    private function cleanupDedupCache(): void
    {
        $now = time();
        foreach ($this->processedMessages as $id => $timestamp) {
            if (($now - $timestamp) >= self::DEDUP_TTL) {
                unset($this->processedMessages[$id]);
            }
        }
    }

    /**
     * Cleanup all connections on shutdown
     */
    private function cleanup(): void
    {
        $this->info('🧹 Cleaning up all connections...');

        foreach (array_keys($this->connections) as $key) {
            $this->stopConnection($key);
        }

        // Wait for fibers to finish
        $maxWait = 5;
        $waited = 0;
        while (count($this->activeFibers) > 0 && $waited < $maxWait) {
            $this->info("Waiting for " . count($this->activeFibers) . " fibers to finish...");
            delay(0.5);
            $waited += 0.5;
        }

        if (count($this->activeFibers) > 0) {
            $this->warn("⚠️  {count($this->activeFibers)} fibers still active after {$maxWait}s");
        }

        $this->logStats(true);
        Cache::forget('ws_v2_active_accounts');
    }

    /**
     * Log statistics if needed
     */
    private function logStatsIfNeeded(): void
    {
        $now = time();
        if ($now - $this->lastStatsLog >= self::STATS_INTERVAL) {
            $this->logStats();
            $this->lastStatsLog = $now;
        }
    }

    /**
     * Log current statistics
     */
    private function logStats(bool $force = false): void
    {
        $stats = [
            'active_connections' => count($this->connections),
            'active_fibers' => count($this->activeFibers),
            'total_events' => $this->totalEventsReceived,
            'total_webhooks' => $this->totalWebhooksSent,
            'total_duplicates_skipped' => $this->totalDuplicatesSkipped,
            'total_reconnects' => $this->totalReconnects,
            'total_errors' => $this->totalErrors,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ];

        if ($force || $this->option('debug')) {
            $this->info('📊 Stats: ' . json_encode($stats, JSON_PRETTY_PRINT));
        }

        Log::info('WebSocket Manager V2 stats', $stats);

        // Detailed fiber stats in debug mode
        if ($this->option('debug')) {
            foreach ($this->activeFibers as $fiberId => $info) {
                $uptime = time() - $info['started_at'];
                $this->line(sprintf(
                    "  Fiber %s: account #%d, uptime %ds, reconnects: %d, events: %d",
                    substr($fiberId, 0, 12),
                    $info['account_id'],
                    $uptime,
                    $info['reconnects'],
                    $info['events_received']
                ));
            }
        }
    }
}

