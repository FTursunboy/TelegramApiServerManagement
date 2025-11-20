<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;

class TelegramAccount extends Model
{
    protected $fillable = [
        'telegram_app_id',
        'type',
        'phone',
        'bot_token',
        'session_name',
        'webhook_url',
        'container_name',
        'container_port',
        'status',
        'last_error',
    ];

    protected $casts = [
        'status' => AccountStatus::class,
        'type' => AccountType::class,
        'container_port' => 'integer',
    ];

    // Relations
    public function telegramApp()
    {
        return $this->belongsTo(TelegramApp::class);
    }

    // Простые хелперы
    public function isReady(): bool
    {
        return $this->status === AccountStatus::READY;
    }

    public function hasContainer(): bool
    {
        return !is_null($this->container_name);
    }

    public function getApiUrl(): string
    {
        return "http://127.0.0.1:{$this->container_port}";
    }
}