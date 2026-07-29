<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['product_id', 'code', 'name', 'description', 'active', 'public', 'display_order'])]
class Bundle extends Model
{
    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'bundle_services')
            ->withPivot(['included_value', 'included'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<BundleService, $this>
     */
    public function bundleServices(): HasMany
    {
        return $this->hasMany(BundleService::class);
    }

    /**
     * @return HasMany<ServicePrice, $this>
     */
    public function servicePrices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'public' => 'boolean',
            'display_order' => 'integer',
        ];
    }
}
