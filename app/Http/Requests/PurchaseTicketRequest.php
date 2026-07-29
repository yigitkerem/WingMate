<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'offer_ids' => ['required', 'array', 'min:1', 'max:2'],
            'offer_ids.*' => ['integer', 'exists:offers,id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'passport_number' => ['nullable', 'string', 'max:32'],
            'adults' => ['required', 'integer', 'min:1', 'max:9'],
            'children' => ['required', 'integer', 'min:0', 'max:9'],
            'babies' => ['required', 'integer', 'min:0', 'max:9'],
        ];
    }
}
