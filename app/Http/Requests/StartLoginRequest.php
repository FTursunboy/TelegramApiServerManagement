<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request для начала авторизации.
 * Создаёт TelegramApp и TelegramAccount автоматически.
 */
class StartLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'api_id' => 'required|string',
            'api_hash' => 'required|string|min:32',

            'type' => ['required', Rule::in(['user', 'bot'])],
            'phone' => 'required_if:type,user|nullable|string',
            'bot_token' => 'required_if:type,bot|nullable|string',

            'webhook_url' => 'required|url',

            'force_recreate' => 'nullable|boolean',

            'session_name' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'api_id.required' => 'API ID обязателен',
            'api_hash.required' => 'API Hash обязателен',
            'api_hash.min' => 'API Hash должен быть минимум 32 символа',
            'type.required' => 'Тип аккаунта обязателен (user или bot)',
            'phone.required_if' => 'Номер телефона обязателен для user',
            'bot_token.required_if' => 'Bot token обязателен для bot',
            'webhook_url.required' => 'Webhook URL обязателен',
            'webhook_url.url' => 'Webhook URL должен быть валидным',
        ];
    }
}
