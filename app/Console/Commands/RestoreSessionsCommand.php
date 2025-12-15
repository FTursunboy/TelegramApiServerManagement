<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\TasApiService;
use Illuminate\Console\Command;

class RestoreSessionsCommand extends Command
{
    protected $signature = 'sessions:restore';
    protected $description = 'Restore all active sessions to TAS containers after restart';

    public function __construct(
        private TasApiService $tas
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🔄 Restoring sessions to TAS containers...');

        $accounts = TelegramAccount::whereNotNull('container_name')
            ->whereNotNull('container_port')
            ->whereNotNull('container_id')
            ->with('telegramApp')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('No accounts found with active containers.');
            return 0;
        }

        $this->info("Found {$accounts->count()} account(s) to restore.");

        foreach ($accounts as $account) {
            try {
                $this->line("🔧 Restoring: {$account->session_name} (port: {$account->container_port})");

                // Add session to TAS
                $this->tas->addSession($account->container_port, $account->session_name);

                // Save session settings
                $app = $account->telegramApp;
                $this->tas->saveSessionSettings(
                    $account->container_port,
                    $account->session_name,
                    $app->api_id,
                    $app->api_hash
                );

                $this->info("✅ Restored: {$account->session_name}");

            } catch (\Exception $e) {
                $this->error("❌ Failed to restore {$account->session_name}: {$e->getMessage()}");
            }
        }

        $this->info('✅ Session restoration completed!');
        return 0;
    }
}

