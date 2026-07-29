<?php

namespace App\Support;

final class ServiceValue
{
    public static function isEnabled(mixed $value): bool
    {
        if (is_array($value)) {
            if (array_key_exists('allowed', $value)) {
                return (bool) $value['allowed'];
            }

            if (array_key_exists('included', $value)) {
                return (bool) $value['included'];
            }

            if (array_key_exists('unlimited', $value) && $value['unlimited'] === true) {
                return true;
            }

            foreach (['amount', 'window_hours', 'data_mb'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key]) && (float) $value[$key] > 0) {
                    return true;
                }
            }

            foreach (['seat_type', 'tier', 'label'] as $key) {
                if (isset($value[$key]) && is_string($value[$key]) && $value[$key] !== '') {
                    return true;
                }
            }

            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((float) $value) > 0;
        }

        return $value !== null && $value !== '';
    }

    public static function amount(mixed $value, mixed $default = null): mixed
    {
        if (is_array($value)) {
            return $value['amount'] ?? $value['window_hours'] ?? $value['data_mb'] ?? $default;
        }

        if (is_bool($value) || is_numeric($value) || is_string($value)) {
            return $value;
        }

        return $default;
    }
}
