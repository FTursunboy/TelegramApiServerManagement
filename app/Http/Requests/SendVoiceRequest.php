<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request для отправки голосового сообщения
 */
class SendVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Session identifier
            'session_name' => 'required|string',
            
            // Recipient (username, phone, or ID)
            'peer' => 'required|string',
            
            // Voice file path or URL
            'voice_path' => 'required|string',
            
            // Optional caption
            'caption' => 'nullable|string|max:1024',
        ];
    }

    public function messages(): array
    {
        return [
            'session_name.required' => 'Session name обязателен',
            'peer.required' => 'Получатель обязателен',
            'voice_path.required' => 'Путь к голосовому файлу обязателен',
            'caption.max' => 'Подпись слишком длинная (макс. 1024 символа)',
        ];
    }
}

