<?php

namespace App\Models;

use Database\Factories\AirportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'code'])]
class Airport extends Model
{
    /** @use HasFactory<AirportFactory> */
    use HasFactory;

    public function originFlights(): HasMany
    {
        return $this->hasMany(Flight::class, 'origin_airport_id');
    }

    public function destinationFlights(): HasMany
    {
        return $this->hasMany(Flight::class, 'destination_airport_id');
    }

    public function flights(): HasMany
    {
        $flight = $this->newRelatedInstance(Flight::class);

        return Relation::noConstraints(fn (): HasMany => $this->newHasMany(
            $flight->newQuery()->where(function (Builder $query): void {
                $query
                    ->where('origin_airport_id', $this->getKey())
                    ->orWhere('destination_airport_id', $this->getKey());
            }),
            $this,
            $flight->qualifyColumn('origin_airport_id'),
            $this->getKeyName(),
        ));
    }
}
