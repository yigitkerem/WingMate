<?php

namespace App\Pricing;

use App\Models\ServiceConstraint;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ServiceConstraintValidator
{
    /**
     * @param  array<string, array{service_id: int, code: string, quantity: int, value?: mixed, included?: bool, price?: float|int, source?: string}>  $services
     */
    public function validate(array $services): void
    {
        $byId = collect($services)
            ->filter(fn (array $service): bool => $this->serviceIsEnabled($service['value'] ?? true))
            ->keyBy('service_id');

        ServiceConstraint::query()
            ->where('active', true)
            ->with(['service:id,code', 'relatedService:id,code'])
            ->get()
            ->each(function (ServiceConstraint $constraint) use ($byId): void {
                if (! $byId->has($constraint->service_id)) {
                    return;
                }

                $service = $byId->get($constraint->service_id);
                $parameters = is_array($constraint->parameters) ? $constraint->parameters : [];
                $valid = match ($constraint->type) {
                    'requires' => $constraint->related_service_id === null || $byId->has($constraint->related_service_id),
                    'conflicts' => $constraint->related_service_id === null || ! $byId->has($constraint->related_service_id),
                    'min_quantity' => (int) $service['quantity'] >= (int) Arr::get($parameters, 'quantity', 0),
                    'max_quantity' => (int) $service['quantity'] <= (int) Arr::get($parameters, 'quantity', 99),
                    default => true,
                };

                if (! $valid) {
                    throw ValidationException::withMessages([
                        'services' => $constraint->message ?: 'This service combination is not available.',
                    ]);
                }
            });
    }

    private function serviceIsEnabled(mixed $value): bool
    {
        if (is_array($value)) {
            return ((float) ($value['amount'] ?? 0)) > 0;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((float) $value) > 0;
        }

        return $value !== null && $value !== '';
    }
}
