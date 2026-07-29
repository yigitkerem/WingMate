<?php

namespace App\Pricing;

use App\Models\PricingRule;
use App\Models\Service;
use Illuminate\Support\Arr;

class RuleEngine
{
    public function __construct(private readonly ExpressionEvaluator $evaluator) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array{components: array<int, array<string, mixed>>, services: array<string, array<string, mixed>>, discount: float, total: float}  $state
     * @return array{components: array<int, array<string, mixed>>, services: array<string, array<string, mixed>>, discount: float, total: float, matched_rules: array<int, int>}
     */
    public function apply(array $context, array $state): array
    {
        $blockedTargets = [];
        $matchedRules = [];

        PricingRule::query()
            ->current()
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->each(function (PricingRule $rule) use (&$state, &$blockedTargets, &$matchedRules, $context): void {
                if (! $this->evaluator->evaluate($rule->condition_expression, $context)) {
                    return;
                }

                $actions = $rule->actions ?? [];

                foreach ($actions as $action) {
                    $target = (string) Arr::get($action, 'target', Arr::get($action, 'service_code', 'total'));

                    if (in_array($target, $blockedTargets, true)) {
                        continue;
                    }

                    $state = $this->applyAction($state, $rule, $action);
                }

                $matchedRules[] = $rule->id;

                if (! $rule->stackable) {
                    foreach ($actions as $action) {
                        $blockedTargets[] = (string) Arr::get($action, 'target', Arr::get($action, 'service_code', 'total'));
                    }
                }
            });

        return [
            ...$state,
            'matched_rules' => $matchedRules,
        ];
    }

    /**
     * @param  array{components: array<int, array<string, mixed>>, services: array<string, array<string, mixed>>, discount: float, total: float}  $state
     * @param  array<string, mixed>  $action
     * @return array{components: array<int, array<string, mixed>>, services: array<string, array<string, mixed>>, discount: float, total: float}
     */
    private function applyAction(array $state, PricingRule $rule, array $action): array
    {
        $type = (string) ($action['type'] ?? '');
        $label = (string) ($action['label'] ?? $rule->name);
        $code = (string) ($action['code'] ?? 'rule_'.$rule->id);

        if ($type === 'percentage_discount') {
            $amount = round($state['total'] * ((float) $action['value'] / 100), 2);
            $state['discount'] += $amount;
            $state['total'] -= $amount;
            $state['components'][] = compact('code', 'label') + ['type' => 'discount', 'amount' => -$amount, 'pricing_rule_id' => $rule->id];
        }

        if ($type === 'percentage_surcharge') {
            $amount = round($state['total'] * ((float) $action['value'] / 100), 2);
            $state['total'] += $amount;
            $state['components'][] = compact('code', 'label') + ['type' => 'surcharge', 'amount' => $amount, 'pricing_rule_id' => $rule->id];
        }

        if ($type === 'fixed_discount') {
            $amount = min((float) $action['value'], $state['total']);
            $state['discount'] += $amount;
            $state['total'] -= $amount;
            $state['components'][] = compact('code', 'label') + ['type' => 'discount', 'amount' => -$amount, 'pricing_rule_id' => $rule->id];
        }

        if ($type === 'fixed_fare') {
            $amount = (float) $action['value'];
            $adjustment = $amount - $state['total'];
            $state['total'] = $amount;
            $state['components'][] = compact('code', 'label') + ['type' => 'override', 'amount' => $adjustment, 'pricing_rule_id' => $rule->id];
        }

        if ($type === 'include_service') {
            $service = Service::query()->where('code', (string) $action['service_code'])->first();

            if ($service instanceof Service) {
                $state['services'][$service->code] = [
                    'service_id' => $service->id,
                    'code' => $service->code,
                    'value' => $action['value'] ?? true,
                    'quantity' => (int) ($action['quantity'] ?? 1),
                    'included' => true,
                    'price' => 0,
                    'source' => 'rule',
                ];
            }
        }

        return $state;
    }
}
