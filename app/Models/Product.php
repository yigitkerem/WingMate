<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'active'])]
class Product extends Model
{
    /**
     * @return HasMany<Bundle, $this>
     */
    public function bundles(): HasMany
    {
        return $this->hasMany(Bundle::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
