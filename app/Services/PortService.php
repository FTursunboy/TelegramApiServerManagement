<?php

namespace App\Services;

use App\Models\TelegramAccount;
use Illuminate\Support\Facades\Cache;

class PortService
{
    private int $startPort;
    private int $endPort;

    public function __construct()
    {
        $this->startPort = config('tas.port_range.start', 9510);
        $this->endPort = config('tas.port_range.end', 9600);
    }

    public function allocate(): int
    {
        $usedPorts = $this->getUsedPorts();
        for ($port = $this->startPort; $port <= $this->endPort; $port++) {
            if (!in_array($port, $usedPorts)) {
                Cache::put("port_reserved_{$port}", true, now()->addMinutes(5));
                return $port;
            }
        }

        throw new \RuntimeException('No free ports available in range ' . $this->startPort . '-' . $this->endPort);
    }


    public function release(int $port): void
    {
        Cache::forget("port_reserved_{$port}");
    }

    /**
     * Получить список занятых портов
     */
    private function getUsedPorts(): array
    {
        $dbPorts = TelegramAccount::whereNotNull('container_port')
            ->pluck('container_port')
            ->toArray();

        $reservedPorts = [];
        for ($port = $this->startPort; $port <= $this->endPort; $port++) {
            if (Cache::has("port_reserved_{$port}")) {
                $reservedPorts[] = $port;
            }
        }

        return array_unique(array_merge($dbPorts, $reservedPorts));
    }

    public function isAvailable(int $port): bool
    {
        $usedPorts = $this->getUsedPorts();
        return !in_array($port, $usedPorts);
    }

    public function getStats(): array
    {
        $usedPorts = $this->getUsedPorts();
        $totalPorts = $this->endPort - $this->startPort + 1;

        return [
            'total' => $totalPorts,
            'used' => count($usedPorts),
            'available' => $totalPorts - count($usedPorts),
            'range' => [
                'start' => $this->startPort,
                'end' => $this->endPort,
            ],
        ];
    }
}
