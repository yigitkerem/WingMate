<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_id', 'bundle_id', 'currency', 'unit_price', 'min_quantity', 'max_quantity', 'valid_from', 'valid_until', 'active'])]
class ServicePrice extends Model
{
    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Bundle, $this>
     */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'active' => 'boolean',
        ];
    }
}
