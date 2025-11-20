<?php

return [
    // Docker настройки
    'docker' => [
        'image' => env('TAS_DOCKER_IMAGE', 'xtrime/telegram-api-server:latest'),
        'host' => env('DOCKER_HOST', 'unix:///var/run/docker.sock'),
        'code_path' => env('TAS_CODE_PATH', storage_path('tas-code')),
    ],

    // TAS API credentials для доступа к контейнерам
    'api' => [
        'username' => env('TAS_API_USERNAME', 'admin'),
        'password' => env('TAS_API_PASSWORD', 'admin'),
    ],

    // ENV переменные для контейнеров
    'container_env' => [
        'ip_whitelist' => env('TAS_IP_WHITELIST', '127.0.0.1'),
        // JSON формат: {"username":"password","admin":"secret"}
        'passwords' => env('TAS_PASSWORDS', '{"admin":"admin"}'),
    ],

    // Порты
    'port_range' => [
        'start' => env('TAS_PORT_START', 9510),
        'end' => env('TAS_PORT_END', 9600),
    ],

    // Health check
    'health_check' => [
        'timeout' => env('TAS_HEALTH_TIMEOUT', 30),
        'interval' => env('TAS_HEALTH_INTERVAL', 2),
        'max_attempts' => env('TAS_HEALTH_MAX_ATTEMPTS', 15),
    ],
];
