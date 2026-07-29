<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $preset
 * @property int $priority
 * @property bool $active
 * @property bool $stackable
 * @property string $condition_expression
 * @property array<int, array<string, mixed>>|null $actions
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 */
#[Fillable(['name', 'preset', 'priority', 'active', 'stackable', 'condition_expression', 'actions', 'valid_from', 'valid_until'])]
class PricingRule extends Model
{
    /**
     * @return HasMany<RuleExecutionLog, $this>
     */
    public function executionLogs(): HasMany
    {
        return $this->hasMany(RuleExecutionLog::class);
    }

    /**
     * @param  Builder<PricingRule>  $query
     * @return Builder<PricingRule>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn (Builder $query): Builder => $query->whereNull('valid_until')->orWhere('valid_until', '>=', now()));
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'stackable' => 'boolean',
            'actions' => 'array',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }
}
