<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'source', 'status', 'search_parameters', 'raw_response', 'imported_count', 'message'])]
class PriceImportBatch extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PriceImportItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PriceImportItem::class);
    }

    protected function casts(): array
    {
        return [
            'search_parameters' => 'array',
            'raw_response' => 'array',
        ];
    }
}
