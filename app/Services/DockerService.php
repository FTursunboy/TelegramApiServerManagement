<?php

namespace App\Services;

use App\Exceptions\ContainerException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DockerService
{
    private string $dockerHost;
    private bool $useUnixSocket;

    public function __construct()
    {
        $this->dockerHost = config('tas.docker.host', 'unix:///var/run/docker.sock');
        $this->useUnixSocket = str_starts_with($this->dockerHost, 'unix://');
    }

    /**
     * Получить HTTP client для Docker API
     */
    private function getClient()
    {
        if ($this->useUnixSocket) {
            // Для Unix socket используем специальный адаптер
            $socketPath = str_replace('unix://', '', $this->dockerHost);

            return Http::withOptions([
                'base_uri' => 'http://localhost',
                'curl' => [
                    CURLOPT_UNIX_SOCKET_PATH => $socketPath,
                ],
            ]);
        } else {
            $host = str_replace('tcp://', 'http://', $this->dockerHost);
            return Http::baseUrl($host);
        }
    }

    /**
     * Создать контейнер через Docker API
     */
    public function createContainer(
        string $name,
        int $hostPort,
        string $sessionName,
        string $apiId,
        string $apiHash
    ): string {
        try {

            if ($this->containerExists($name)) {
                Log::warning("Container {$name} already exists, removing it");
                $this->removeContainer($name);
            }

            $image = config('tas.docker.image');
            $codePath = config('tas.docker.code_path');

            $this->ensureImageExists($image);
            $this->ensureCodeExists($codePath);

            $volumeName = "tas_session_{$sessionName}";

            $config = [
                'Image' => $image,
                'Cmd' => ['-s', $sessionName],
                'Env' => [
                    'SERVER_PORT=9503',
                    'IP_WHITELIST=' . config('tas.container_env.ip_whitelist'),
                    'PASSWORDS=' . config('tas.container_env.passwords'),
                    'TELEGRAM_API_ID=' . $apiId,
                    'TELEGRAM_API_HASH=' . $apiHash,
                    'LOGGER_LEVEL=2',
                    'DB_TYPE=memory',
                ],
                'WorkingDir' => '/app',
                'HostConfig' => [
                    'PortBindings' => [
                        '9503/tcp' => [
                            [
                                'HostIp' => '127.0.0.1',
                                'HostPort' => (string)$hostPort,
                            ],
                        ],
                    ],
                    'Binds' => [
                        "{$codePath}:/app",
                        "{$volumeName}:/app/sessions",
                    ],
                ],
                'ExposedPorts' => [
                    '9503/tcp' => (object)[],
                ],
            ];

            $response = $this->getClient()
                ->post("/v1.43/containers/create?name={$name}", $config);

            if (!$response->successful()) {
                throw new ContainerException(
                    "Failed to create container: " . $response->body()
                );
            }

            $data = $response->json();
            $containerId = $data['Id'] ?? null;

            if (!$containerId) {
                throw new ContainerException('Container ID not returned');
            }


            $startResponse = $this->getClient()
                ->post("/v1.43/containers/{$containerId}/start");

            if (!$startResponse->successful()) {
                $this->removeContainer($name);
                throw new ContainerException(
                    "Failed to start container: " . $startResponse->body()
                );
            }

            return $containerId;

        } catch (\Exception $e) {
            Log::error('Failed to create container', [
                'name' => $name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new ContainerException("Failed to create container: {$e->getMessage()}");
        }
    }

    public function stopContainer(string $name): void
    {
        try {
            $response = $this->getClient()
                ->post("/v1.43/containers/{$name}/stop?t=10");

            if ($response->successful() || $response->status() === 304) {
                Log::info('Container stopped', ['name' => $name]);
            } else {
                Log::warning("Failed to stop container {$name}: {$response->body()}");
            }
        } catch (\Exception $e) {
            Log::warning("Failed to stop container {$name}: {$e->getMessage()}");
        }
    }


    public function removeContainer(string $name): void
    {
        try {
            $response = $this->getClient()
                ->delete("/v1.43/containers/{$name}?force=true");

            if ($response->successful() || $response->status() === 404) {
                Log::info('Container removed', ['name' => $name]);
            } else {
                Log::warning("Failed to remove container {$name}: {$response->body()}");
            }
        } catch (\Exception $e) {
            Log::warning("Failed to remove container {$name}: {$e->getMessage()}");
        }
    }


    public function isRunning(string $name): bool
    {
        try {
            $info = $this->getContainerInfo($name);
            return $info && ($info['state'] ?? '') === 'running';
        } catch (\Exception $e) {
            return false;
        }
    }

    public function containerExists(string $name): bool
    {
        try {
            $response = $this->getClient()
                ->get("/v1.43/containers/{$name}/json");

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getContainerInfo(string $name): ?array
    {
        try {
            $response = $this->getClient()
                ->get("/v1.43/containers/{$name}/json");

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            return [
                'id' => $data['Id'] ?? null,
                'name' => ltrim($data['Name'] ?? '', '/'),
                'state' => strtolower($data['State']['Status'] ?? 'unknown'),
                'running' => $data['State']['Running'] ?? false,
                'created' => $data['Created'] ?? null,
                'image' => $data['Config']['Image'] ?? null,
                'ports' => $this->parsePorts($data['NetworkSettings']['Ports'] ?? []),
            ];
        } catch (\Exception $e) {
            Log::warning("Failed to get container info for {$name}: {$e->getMessage()}");
            return null;
        }
    }

    private function parsePorts(array $ports): array
    {
        $result = [];
        foreach ($ports as $containerPort => $hostPorts) {
            if (is_array($hostPorts)) {
                foreach ($hostPorts as $binding) {
                    $result[] = [
                        'container_port' => $containerPort,
                        'host_ip' => $binding['HostIp'] ?? '',
                        'host_port' => $binding['HostPort'] ?? '',
                    ];
                }
            }
        }
        return $result;
    }

    public function waitUntilReady(int $port, int $timeout = 30): void
    {
        $interval = config('tas.health_check.interval', 2);
        $maxAttempts = (int)($timeout / $interval);

        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                $response = Http::timeout(3)
                    ->withBasicAuth(
                        config('tas.api.username'),
                        config('tas.api.password')
                    )
                    ->get("http://127.0.0.1:{$port}/system/getSessionList");

                if ($response->successful()) {
                    return;
                }
            } catch (\Exception $e) {
                Log::debug('Waiting for container', [
                    'port' => $port,
                    'attempt' => $i + 1,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                ]);
            }

            sleep($interval);
        }

        throw new ContainerException("Container did not become ready within {$timeout} seconds");
    }

    /**
     * Получить логи контейнера
     */
    public function getLogs(string $name, int $tail = 100): string
    {
        try {
            $response = $this->getClient()
                ->get("/v1.43/containers/{$name}/logs?stdout=true&stderr=true&tail={$tail}");

            if ($response->successful()) {
                // Docker API возвращает логи с специальными заголовками
                // Нужно их убрать для читаемости
                return $this->cleanDockerLogs($response->body());
            }

            return "Failed to get logs: {$response->body()}";
        } catch (\Exception $e) {
            return "Failed to get logs: {$e->getMessage()}";
        }
    }

    /**
     * Очистить Docker логи от служебных заголовков
     */
    private function cleanDockerLogs(string $raw): string
    {
        // Docker API добавляет 8-байтовый заголовок к каждой строке
        // Формат: [STREAM_TYPE, 0, 0, 0, SIZE1, SIZE2, SIZE3, SIZE4]
        $lines = [];
        $offset = 0;
        $length = strlen($raw);

        while ($offset < $length) {
            if ($offset + 8 > $length) break;

            // Пропустить 8-байтовый заголовок
            $header = substr($raw, $offset, 8);
            $offset += 8;

            // Получить размер сообщения
            $size = unpack('N', substr($header, 4, 4))[1] ?? 0;

            if ($size > 0 && $offset + $size <= $length) {
                $lines[] = substr($raw, $offset, $size);
                $offset += $size;
            } else {
                break;
            }
        }

        return implode('', $lines);
    }

    /**
     * Перезапустить контейнер
     */
    public function restartContainer(string $name): void
    {
        try {
            $response = $this->getClient()
                ->post("/v1.43/containers/{$name}/restart");

            if ($response->successful()) {
                Log::info('Container restarted', ['name' => $name]);
            } else {
                throw new ContainerException("Failed to restart: {$response->body()}");
            }
        } catch (\Exception $e) {
            throw new ContainerException("Failed to restart container: {$e->getMessage()}");
        }
    }

    /**
     * Получить список всех контейнеров
     */
    public function listContainers(array $filters = []): array
    {
        try {
            $query = ['all' => 'true'];

            if (!empty($filters)) {
                $query['filters'] = json_encode($filters);
            }

            $response = $this->getClient()
                ->get('/v1.43/containers/json', $query);

            if (!$response->successful()) {
                return [];
            }

            $containers = [];
            foreach ($response->json() as $container) {
                $containers[] = [
                    'id' => $container['Id'] ?? null,
                    'names' => $container['Names'] ?? [],
                    'image' => $container['Image'] ?? null,
                    'state' => $container['State'] ?? null,
                    'status' => $container['Status'] ?? null,
                    'created' => $container['Created'] ?? null,
                    'ports' => $this->parsePorts($container['Ports'] ?? []),
                ];
            }

            return $containers;
        } catch (\Exception $e) {
            Log::error('Failed to list containers', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить список всех контейнеров TAS
     */
    public function listTasContainers(): array
    {
        return $this->listContainers([
            'name' => ['tas_'],
        ]);
    }

    /**
     * Очистить остановленные контейнеры
     */
    public function pruneStoppedContainers(): array
    {
        try {
            $containers = $this->listContainers([
                'name' => ['tas_'],
                'status' => ['exited'],
            ]);

            $removed = [];
            foreach ($containers as $container) {
                $containerId = $container['id'];
                $this->removeContainer($containerId);
                $removed[] = $containerId;
            }

            Log::info('Pruned stopped containers', ['count' => count($removed)]);

            return $removed;
        } catch (\Exception $e) {
            Log::error('Failed to prune containers', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Проверить доступность Docker
     */
    public function isDockerAvailable(): bool
    {
        try {
            $response = $this->getClient()->get('/v1.43/info');
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Docker is not available', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Получить информацию о Docker
     */
    public function getDockerInfo(): array
    {
        try {
            $response = $this->getClient()->get('/v1.43/info');

            if (!$response->successful()) {
                return [];
            }

            $data = $response->json();

            return [
                'containers' => $data['Containers'] ?? 0,
                'containers_running' => $data['ContainersRunning'] ?? 0,
                'containers_paused' => $data['ContainersPaused'] ?? 0,
                'containers_stopped' => $data['ContainersStopped'] ?? 0,
                'images' => $data['Images'] ?? 0,
                'server_version' => $data['ServerVersion'] ?? null,
                'operating_system' => $data['OperatingSystem'] ?? null,
                'architecture' => $data['Architecture'] ?? null,
                'memory' => $data['MemTotal'] ?? 0,
                'cpus' => $data['NCPU'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get Docker info', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить статистику контейнера
     */
    public function getContainerStats(string $name): array
    {
        try {
            $response = $this->getClient()
                ->get("/v1.43/containers/{$name}/stats?stream=false");

            if (!$response->successful()) {
                return [];
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error("Failed to get stats for {$name}", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Создать volume для сессии
     */
    public function createVolume(string $name): bool
    {
        try {
            $response = $this->getClient()
                ->post('/v1.43/volumes/create', [
                    'Name' => $name,
                    'Driver' => 'local',
                ]);

            if ($response->successful() || $response->status() === 201) {
                Log::info('Volume created', ['name' => $name]);
                return true;
            }

            // Если volume уже существует - это не ошибка
            if ($response->status() === 409) {
                Log::debug('Volume already exists', ['name' => $name]);
                return true;
            }

            Log::warning('Failed to create volume', [
                'name' => $name,
                'response' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to create volume', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Удалить volume
     */
    public function removeVolume(string $name): bool
    {
        try {
            $response = $this->getClient()
                ->delete("/v1.43/volumes/{$name}");

            if ($response->successful() || $response->status() === 404) {
                Log::info('Volume removed', ['name' => $name]);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('Failed to remove volume', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Pull образа
     */
    public function pullImage(string $image): bool
    {
        try {
            Log::info('Pulling Docker image', ['image' => $image]);

            $response = $this->getClient()
                ->timeout(300)
                ->post("/v1.43/images/create?fromImage={$image}");

            if ($response->successful()) {
                Log::info('Image pulled successfully', ['image' => $image]);
                return true;
            }

            Log::error('Failed to pull image', [
                'image' => $image,
                'response' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to pull image', [
                'image' => $image,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function ensureImageExists(string $image): void
    {
        try {
            $response = $this->getClient()
                ->get("/v1.43/images/{$image}/json");

            if ($response->successful()) {
                return;
            }
        } catch (\Exception $e) {
        }

        Log::info('Image not found locally, pulling...', ['image' => $image]);

        if (!$this->pullImage($image)) {
            throw new ContainerException("Failed to pull image: {$image}");
        }
    }

    private function ensureCodeExists(string $codePath): void
    {
        if (is_dir($codePath) && file_exists("{$codePath}/server.php")) {
            return;
        }

        Log::info('TAS code not found, cloning...', ['path' => $codePath]);

        $parentDir = dirname($codePath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $command = sprintf(
            'git clone https://github.com/xtrime-ru/TelegramApiServer.git %s 2>&1',
            escapeshellarg($codePath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new ContainerException("Failed to clone TAS code: " . implode("\n", $output));
        }

        if (!file_exists("{$codePath}/.env.docker")) {
            copy("{$codePath}/.env.docker.example", "{$codePath}/.env.docker");
        }

        Log::info('TAS code cloned successfully', ['path' => $codePath]);
    }
}
