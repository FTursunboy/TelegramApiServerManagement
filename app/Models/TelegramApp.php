<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramApp extends Model
{
    protected $fillable = [
        'tenant_id',
        'api_id',
        'api_hash',
        'status',
        'name',
        'description',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
    ];

    protected $hidden = [
        'api_hash', // Скрываем при сериализации
    ];

    public function setApiHashAttribute($value): void
    {
        if ($value) {
            $this->attributes['api_hash'] = encrypt($value);
        }
    }


    public function getApiHashAttribute($value): ?string
    {
        if ($value) {
            try {
                return decrypt($value);
            } catch (\Exception $e) {
                \Log::error('Failed to decrypt api_hash', [
                    'telegram_app_id' => $this->id,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }
        return null;
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(TelegramAccount::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBanned(): bool
    {
        return $this->status === 'banned';
    }

    public function markAsBanned(): void
    {
        $this->update(['status' => 'banned']);
    }

    public function markAsActive(): void
    {
        $this->update(['status' => 'active']);
    }

  
    public function getCredentials(): array
    {
        return [
            'api_id' => $this->api_id,
            'api_hash' => $this->api_hash
        ];
    }
}