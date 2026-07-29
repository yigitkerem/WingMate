<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChatMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string', 'max:80'],
            'message_id' => ['nullable', 'string', 'max:80'],
            'message' => ['required', 'string', 'min:1', 'max:2000'],
            'source' => ['nullable', Rule::in(['ours'])],
            'language' => ['nullable', Rule::in(['tr', 'en'])],
        ];
    }
}
