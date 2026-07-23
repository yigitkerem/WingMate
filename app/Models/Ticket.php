<?php

namespace App\Models;

use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $availability_id
 * @property int $pnr_id
 * @property bool $flown
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['availability_id', 'pnr_id', 'flown'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected $attributes = [
        'flown' => false,
    ];

    public function availability(): BelongsTo
    {
        return $this->belongsTo(Availability::class);
    }

    public function pnr(): BelongsTo
    {
        return $this->belongsTo(Pnr::class);
    }

    protected function casts(): array
    {
        return [
            'flown' => 'boolean',
        ];
    }
}
