<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request для операций с сессией (stop, restart, status)
 */
class SessionRequest extends FormRequest
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
            
            // Optional: remove container on stop
            'remove_container' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'session_name.required' => 'Session name обязателен',
        ];
    }
}

