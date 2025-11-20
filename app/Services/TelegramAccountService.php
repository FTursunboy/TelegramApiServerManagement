<?php

namespace App\Services;

use App\Models\TelegramAccount;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\TelegramApp;
use App\Jobs\ListenToWebSocket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramAccountService
{
    public function __construct(
        private DockerService $docker,
        private TasApiService $tas,
        private PortService $portService,
    ) {}

    public function startLogin(array $data): array
    {
        return DB::transaction(function () use ($data) {
            try {
                $app = $this->findOrCreateTelegramApp(
                    $data['api_id'],
                    $data['api_hash']
                );

                $account = $this->findOrCreateTelegramAccount($app, $data);

                $forceRecreate = $data['force_recreate'] ?? false;
                if ($forceRecreate || !$account->hasContainer()) {
                    $this->createContainer($account);
                } elseif (!$this->docker->isRunning($account->container_name)) {
                    $this->docker->removeContainer($account->container_name);
                    $this->createContainer($account);
                }

                $this->docker->waitUntilReady($account->container_port);

                $this->tas->addSession($account->container_port, $account->session_name);

                $this->configureSession($account);

                ListenToWebSocket::dispatch($account->id);

                return $this->initiateAuth($account);

            } catch (\Exception $e) {
                Log::error('Start login failed', [
                    'data' => $data,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Завершить авторизацию кодом
     */
    public function completeCode(string $sessionName, string $code): array
    {
        $account = TelegramAccount::where('session_name', $sessionName)->firstOrFail();

        if ($account->status !== AccountStatus::WAITING_CODE) {
            throw new \InvalidArgumentException('Account is not waiting for code');
        }

        try {
            $response = $this->tas->completePhoneLogin(
                $account->container_port,
                $account->session_name,
                $code
            );

            if (isset($response['response']['_']) && $response['response']['_'] === 'account.password') {
                $account->update(['status' => AccountStatus::WAITING_2FA]);

                return [
                    'status' => AccountStatus::WAITING_2FA->value,
                    'needs_2fa' => true,
                    'error' => null,
                ];
            }

            $selfInfo = $this->tas->getSelf($account->container_port);
            $userData = $selfInfo['response'] ?? [];

            $account->markAuthorized($userData);

            return [
                'status' => AccountStatus::READY->value,
                'needs_2fa' => false,
                'user_data' => [
                    'id' => $userData['id'] ?? null,
                    'username' => $userData['username'] ?? null,
                    'first_name' => $userData['first_name'] ?? null,
                    'phone' => $userData['phone'] ?? null,
                ],
                'error' => null,
            ];

        } catch (\Exception $e) {
            $account->update([
                'status' => AccountStatus::ERROR,
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function complete2FA(string $sessionName, string $password): array
    {
        $account = TelegramAccount::where('session_name', $sessionName)->firstOrFail();

        if ($account->status !== AccountStatus::WAITING_2FA) {
            throw new \InvalidArgumentException('Account is not waiting for 2FA');
        }

        try {
            $this->tas->complete2faLogin(
                $account->container_port,
                $account->session_name,
                $password
            );

            $selfInfo = $this->tas->getSelf($account->container_port);
            $userData = $selfInfo['response'] ?? [];

            $account->markAuthorized($userData);

            return [
                'status' => AccountStatus::READY->value,
                'user_data' => [
                    'id' => $userData['id'] ?? null,
                    'username' => $userData['username'] ?? null,
                    'first_name' => $userData['first_name'] ?? null,
                ],
                'error' => null,
            ];

        } catch (\Exception $e) {

            $account->update([
                'status' => AccountStatus::ERROR,
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Остановить аккаунт
     */
    public function stop(string $sessionName, bool $removeContainer = true): array
    {
        $account = TelegramAccount::where('session_name', $sessionName)->firstOrFail();

        if (!$account->hasContainer()) {
            return ['status' => 'stopped', 'error' => null];
        }

        $this->docker->stopContainer($account->container_name);

        if ($removeContainer) {
            $this->docker->removeContainer($account->container_name);
            $this->portService->release($account->container_port);
        }

        $account->update([
            'status' => AccountStatus::STOPPED,
            'container_name' => $removeContainer ? null : $account->container_name,
            'container_port' => $removeContainer ? null : $account->container_port,
            'container_id' => $removeContainer ? null : $account->container_id,
        ]);

        return ['status' => 'stopped', 'error' => null];
    }

    private function findOrCreateTelegramApp(string $apiId, string $apiHash): TelegramApp
    {
        $app = TelegramApp::where('api_id', $apiId)->first();

        if (!$app) {
            $app = TelegramApp::create([
                'tenant' => 'default',
                'api_id' => $apiId,
                'api_hash' => $apiHash,
                'status' => 'active',
                'name' => 'App ' . $apiId,
            ]);
        }

        return $app;
    }

    private function findOrCreateTelegramAccount(TelegramApp $app, array $data): TelegramAccount
    {
        $sessionName = $data['session_name'] ?? 'session_' . uniqid();

        $account = TelegramAccount::where('session_name', $sessionName)->first();

        if (!$account) {
            $account = TelegramAccount::create([
                'telegram_app_id' => $app->id,
                'type' => $data['type'],
                'phone' => $data['phone'] ?? null,
                'bot_token' => $data['bot_token'] ?? null,
                'session_name' => $sessionName,
                'webhook_url' => $data['webhook_url'],
                'status' => AccountStatus::CREATING,
            ]);
        } else {
            if ($account->webhook_url !== $data['webhook_url']) {
                $account->update(['webhook_url' => $data['webhook_url']]);
            }
        }

        $account->load('telegramApp');

        return $account;
    }


    private function createContainer(TelegramAccount $account): void
    {
        $port = $this->portService->allocate();
        $containerName = "tas_{$account->id}";
        $sessionName = $account->session_name;

        try {
            $app = $account->telegramApp;

            $containerId = $this->docker->createContainer(
                $containerName,
                $port,
                $sessionName,
                $app->api_id,
                $app->api_hash
            );

            $account->update([
                'container_name' => $containerName,
                'container_port' => $port,
                'container_id' => $containerId,
                'status' => AccountStatus::CREATING,
            ]);

        } catch (\Exception $e) {
            $this->portService->release($port);
            throw $e;
        }
    }

    private function configureSession(TelegramAccount $account): void
    {

        $app = $account->telegramApp;

        $this->tas->saveSessionSettings(
            $account->container_port,
            $account->session_name,
            $app->api_id,
            $app->api_hash
        );
    }


    private function configureWebhook(TelegramAccount $account): void
    {
        try {
            $this->tas->setWebhook(
                $account->container_port,
                $account->webhook_url
            );
        } catch (\Exception $e) {
            Log::warning('Failed to set webhook', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function initiateAuth(TelegramAccount $account): array
    {
        if ($account->type === AccountType::USER) {

            $this->tas->phoneLogin(
                $account->container_port,
                $account->session_name,
                $account->phone
            );

            $account->update(['status' => AccountStatus::WAITING_CODE]);

            return [
                'status' => AccountStatus::WAITING_CODE->value,
                'needs_code' => true,
                'needs_2fa' => false,
                'container' => [
                    'name' => $account->container_name,
                    'port' => $account->container_port,
                    'id' => $account->container_id,
                ],
                'error' => null,
            ];
        } else {

            $this->tas->botLogin(
                $account->container_port,
                $account->session_name,
                $account->bot_token
            );

            $selfInfo = $this->tas->getSelf($account->container_port);
            $botData = $selfInfo['response'] ?? [];

            $account->markAuthorized($botData);

            return [
                'status' => AccountStatus::READY->value,
                'needs_code' => false,
                'needs_2fa' => false,
                'bot_data' => [
                    'id' => $botData['id'] ?? null,
                    'username' => $botData['username'] ?? null,
                    'first_name' => $botData['first_name'] ?? null,
                ],
                'container' => [
                    'name' => $account->container_name,
                    'port' => $account->container_port,
                    'id' => $account->container_id,
                ],
                'error' => null,
            ];
        }
    }
}
