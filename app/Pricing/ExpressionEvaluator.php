<?php

namespace App\Pricing;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ExpressionEvaluator
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function evaluate(string $expression, array $context): bool
    {
        $expression = trim($expression);

        if ($expression === '' || $expression === 'true') {
            return true;
        }

        foreach (preg_split('/\s*\|\|\s*/', $expression) ?: [] as $orPart) {
            $andParts = preg_split('/\s*&&\s*/', trim($orPart)) ?: [];
            $matches = collect($andParts)->every(fn (string $part): bool => $this->evaluateComparison(trim($part), $context));

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function evaluateComparison(string $comparison, array $context): bool
    {
        if ($comparison === '') {
            return true;
        }

        if (preg_match('/^contains\(([^,]+),\s*(.+)\)$/', $comparison, $matches) === 1) {
            $value = Arr::get($context, trim($matches[1]));
            $needle = $this->literal($matches[2], $context);

            return is_array($value)
                ? in_array($needle, $value, true)
                : str_contains((string) $value, (string) $needle);
        }

        if (preg_match('/^(.+?)\s*(==|!=|>=|<=|>|<)\s*(.+)$/', $comparison, $matches) !== 1) {
            return (bool) Arr::get($context, $comparison, false);
        }

        $left = $this->literal($matches[1], $context);
        $operator = $matches[2];
        $right = $this->literal($matches[3], $context);

        if ($operator === '==') {
            return $left == $right;
        }

        if ($operator === '!=') {
            return $left != $right;
        }

        if ($operator === '>=') {
            return $left >= $right;
        }

        if ($operator === '<=') {
            return $left <= $right;
        }

        if ($operator === '>') {
            return $left > $right;
        }

        return $left < $right;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function literal(string $value, array $context): mixed
    {
        $value = trim($value);

        if (Str::startsWith($value, ['"', "'"]) && Str::endsWith($value, ['"', "'"])) {
            return trim($value, '"\'');
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        if ($value === 'true' || $value === 'false') {
            return $value === 'true';
        }

        return Arr::get($context, $value);
    }
}
