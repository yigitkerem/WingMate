<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $service_id
 * @property string $type
 * @property int|null $related_service_id
 * @property array<string, mixed>|null $parameters
 * @property string|null $message
 * @property bool $active
 */
#[Fillable(['service_id', 'type', 'related_service_id', 'parameters', 'message', 'active'])]
class ServiceConstraint extends Model
{
    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function relatedService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'related_service_id');
    }

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'active' => 'boolean',
        ];
    }
}
