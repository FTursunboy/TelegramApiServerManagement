<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request для завершения авторизации по коду из SMS/Telegram
 */
class CompleteCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_name' => 'required|string',

            'code' => 'required|string|min:5|max:6',
        ];
    }

    public function messages(): array
    {
        return [
            'session_name.required' => 'Session name обязателен',
            'code.required' => 'Код обязателен',
            'code.min' => 'Код должен быть минимум 5 символов',
            'code.max' => 'Код должен быть максимум 6 символов',
        ];
    }
}
