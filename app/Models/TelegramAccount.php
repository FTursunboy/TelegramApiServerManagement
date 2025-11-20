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
        'container_id',
        'status',
        'last_error',
        'telegram_user_id',
        'telegram_username',
        'first_name',
        'last_name',
        'last_activity_at',
        'messages_sent_count',
        'authorized_at',
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

    /**
     * Отметить аккаунт как авторизованный
     */
    public function markAuthorized(array $userData): void
    {
        $this->update([
            'status' => AccountStatus::READY,
            'telegram_user_id' => $userData['id'] ?? null,
            'telegram_username' => $userData['username'] ?? null,
            'first_name' => $userData['first_name'] ?? null,
            'last_name' => $userData['last_name'] ?? null,
            'authorized_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * Увеличить счётчик отправленных сообщений
     */
    public function incrementMessageCount(): void
    {
        $this->increment('messages_sent_count');
        $this->update(['last_activity_at' => now()]);
    }
}