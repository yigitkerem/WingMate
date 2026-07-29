<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['offer_id', 'pricing_rule_id', 'matched', 'context', 'actions_applied', 'message'])]
class RuleExecutionLog extends Model
{
    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<PricingRule, $this>
     */
    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class);
    }

    protected function casts(): array
    {
        return [
            'matched' => 'boolean',
            'context' => 'array',
            'actions_applied' => 'array',
        ];
    }
}
