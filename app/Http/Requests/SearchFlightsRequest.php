<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchFlightsRequest extends FormRequest
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
        $isSearchAttempt = $this->isSearchAttempt();

        return [
            'origin_airport_id' => [Rule::requiredIf($isSearchAttempt), 'integer', Rule::exists('airports', 'id')],
            'destination_airport_id' => [Rule::requiredIf($isSearchAttempt), 'integer', Rule::exists('airports', 'id')],
            'trip_type' => [Rule::requiredIf($isSearchAttempt), Rule::in(['one_way', 'round_trip'])],
            'depart_date' => [Rule::requiredIf($isSearchAttempt), 'date'],
            'return_date' => [Rule::requiredIf($this->trip_type === 'round_trip'), 'nullable', 'date', 'after_or_equal:depart_date'],
            'search_mode' => [Rule::requiredIf($isSearchAttempt), Rule::in(['basic', 'full'])],
            'adults' => [Rule::requiredIf($isSearchAttempt), 'integer', 'min:1', 'max:9'],
            'children' => [Rule::requiredIf($isSearchAttempt), 'integer', 'min:0', 'max:9'],
            'babies' => [Rule::requiredIf($isSearchAttempt), 'integer', 'min:0', 'max:9'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isSearchAttempt()) {
                    return;
                }

                if ($this->integer('origin_airport_id') === $this->integer('destination_airport_id')) {
                    $validator->errors()->add('destination_airport_id', 'Choose a different destination.');
                }

                if (($this->integer('adults') + $this->integer('children')) > 9) {
                    $validator->errors()->add('adults', 'Search up to 9 seated passengers.');
                }

                if (($this->integer('adults') + $this->integer('children') + $this->integer('babies')) > 9) {
                    $validator->errors()->add('babies', 'Search up to 9 passengers total.');
                }
            },
        ];
    }

    public function isSearchAttempt(): bool
    {
        return $this->hasAny([
            'origin_airport_id',
            'destination_airport_id',
            'trip_type',
            'depart_date',
            'return_date',
            'search_mode',
            'adults',
            'children',
            'babies',
        ]);
    }
}
