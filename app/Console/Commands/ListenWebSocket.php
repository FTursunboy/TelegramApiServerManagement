<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\WebSocketService;
use Illuminate\Console\Command;
use WebSocket\Client;

class ListenWebSocket extends Command
{
    protected $signature = 'tas:listen {session_name}';
    protected $description = 'Listen to TAS WebSocket and forward private messages to webhook';

    public function handle(WebSocketService $ws): int
    {
        $sessionName = $this->argument('session_name');
        
        $account = TelegramAccount::where('session_name', $sessionName)->first();
        
        if (!$account) {
            $this->error("Session not found: {$sessionName}");
            return 1;
        }

        if (!$account->hasContainer()) {
            $this->error("Container not running for session: {$sessionName}");
            return 1;
        }

        $wsUrl = $ws->getWebSocketUrl($account);
        $this->info("Connecting to: {$wsUrl}");
        $this->info("Webhook URL: {$account->webhook_url}");
        $this->info("Filtering: Private messages only");
        $this->line('');

        try {
            $client = new Client($wsUrl);
            
            $this->info('✓ Connected! Listening for updates...');
            $this->line('Press Ctrl+C to stop');
            $this->line('');

            while (true) {
                try {
                    $message = $client->receive();
                    $update = json_decode($message, true);

                    if (!$update) {
                        continue;
                    }

                    if ($ws->isPrivateMessage($update)) {
                        $data = $ws->extractMessageData($update);
                        
                        $this->line(sprintf(
                            '[%s] Private message from %s: %s',
                            date('H:i:s'),
                            $data['from_id'] ?? 'unknown',
                            substr($data['message'] ?? '', 0, 50)
                        ));

                        $ws->sendToWebhook($account->webhook_url, $data);
                        $this->info('  → Sent to webhook');
                    }

                } catch (\Exception $e) {
                    $this->error("Error: {$e->getMessage()}");
                    sleep(1);
                }
            }

        } catch (\Exception $e) {
            $this->error("Connection failed: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}

