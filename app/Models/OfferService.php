<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $offer_id
 * @property int $service_id
 * @property mixed $value
 * @property int $quantity
 * @property bool $included
 * @property string $price
 * @property string $source
 */
#[Fillable(['offer_id', 'service_id', 'value', 'quantity', 'included', 'price', 'source'])]
class OfferService extends Model
{
    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'included' => 'boolean',
            'price' => 'decimal:2',
        ];
    }
}
