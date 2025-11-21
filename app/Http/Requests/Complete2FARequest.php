<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request для завершения авторизации с 2FA паролем
 */
class Complete2FARequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Session identifier from startLogin response
            'session_name' => 'required|string',
            
            // 2FA password
            'password' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'session_name.required' => 'Session name обязателен',
            'password.required' => '2FA пароль обязателен',
        ];
    }
}