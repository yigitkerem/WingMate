<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['price_import_batch_id', 'flight_id', 'base_fare_id', 'status', 'mapped_payload', 'raw_payload'])]
class PriceImportItem extends Model
{
    /**
     * @return BelongsTo<PriceImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PriceImportBatch::class, 'price_import_batch_id');
    }

    /**
     * @return BelongsTo<Flight, $this>
     */
    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    /**
     * @return BelongsTo<BaseFare, $this>
     */
    public function baseFare(): BelongsTo
    {
        return $this->belongsTo(BaseFare::class);
    }

    protected function casts(): array
    {
        return [
            'mapped_payload' => 'array',
            'raw_payload' => 'array',
        ];
    }
}
