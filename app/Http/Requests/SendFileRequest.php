<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request для отправки файла/документа
 */
class SendFileRequest extends FormRequest
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
            
            // File path or URL
            'file_path' => 'required|string',
            
            // Optional caption
            'caption' => 'nullable|string|max:1024',
            
            // Optional parse mode
            'parse_mode' => 'nullable|in:Markdown,HTML',
        ];
    }

    public function messages(): array
    {
        return [
            'session_name.required' => 'Session name обязателен',
            'peer.required' => 'Получатель обязателен',
            'file_path.required' => 'Путь к файлу обязателен',
            'caption.max' => 'Подпись слишком длинная (макс. 1024 символа)',
        ];
    }
}

