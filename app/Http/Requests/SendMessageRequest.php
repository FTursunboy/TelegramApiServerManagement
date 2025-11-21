<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request для отправки текстового сообщения
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_name' => 'required|string',
            'peer' => 'required|string',
            'message' => 'required|string|max:4096',

            // Parse mode (Markdown or HTML)
            'parse_mode' => 'nullable|in:Markdown,HTML',
        ];
    }

    public function messages(): array
    {
        return [
            'session_name.required' => 'Session name обязателен',
            'peer.required' => 'Получатель обязателен',
            'message.required' => 'Сообщение обязательно',
            'message.max' => 'Сообщение слишком длинное (макс. 4096 символов)',
        ];
    }
}
