<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'category', 'value_type', 'default_unit', 'active'])]
class Service extends Model
{
    /**
     * @return HasMany<ServicePrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    /**
     * @return HasMany<ServiceConstraint, $this>
     */
    public function constraints(): HasMany
    {
        return $this->hasMany(ServiceConstraint::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
