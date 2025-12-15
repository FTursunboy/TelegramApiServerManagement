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
            'session_name' => 'required|string',
            'peer' => 'required|string',
            'file_url' => 'required|string|url',
            'caption' => 'nullable|string|max:1024',
            'parse_mode' => 'nullable|in:Markdown,HTML',
        ];
    }

    public function messages(): array
    {
        return [
            'session_name.required' => 'Session name обязателен',
            'peer.required' => 'Получатель обязателен',
            'file_url.required' => 'URL файла обязателен',
            'file_url.url' => 'Некорректный URL файла',
            'caption.max' => 'Подпись слишком длинная (макс. 1024 символа)',
        ];
    }
}





