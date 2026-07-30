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
            'message' => ['nullable', 'required_without:trigger', 'string', 'min:1', 'max:2000'],
            'source' => ['nullable', Rule::in(['ours'])],
            'language' => ['nullable', Rule::in(['tr', 'en'])],
            'consent' => ['nullable', 'boolean'],
            'trigger' => ['nullable', 'string', 'max:60'],
            'context' => ['nullable', 'array'],
            'context.page' => ['nullable', 'string', 'max:80'],
            'context.form' => ['nullable', 'array'],
            'context.form.origin' => ['nullable', 'string', 'max:120'],
            'context.form.destination' => ['nullable', 'string', 'max:120'],
            'context.form.date' => ['nullable', 'string', 'max:40'],
            'context.form.return_date' => ['nullable', 'string', 'max:40'],
            'context.form.trip_type' => ['nullable', 'string', 'max:20'],
            'context.form.adults' => ['nullable', 'integer', 'min:0', 'max:20'],
            'context.form.children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'context.form.babies' => ['nullable', 'integer', 'min:0', 'max:20'],
            'context.form.submitted' => ['nullable', 'boolean'],
            'context.location' => ['nullable', 'array'],
            'context.location.lat' => ['nullable', 'numeric'],
            'context.location.lng' => ['nullable', 'numeric'],
            'context.trigger_context' => ['nullable', 'array'],
        ];
    }
}
