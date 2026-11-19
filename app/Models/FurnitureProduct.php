<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Models\CustomerPreference;
use Illuminate\Support\Collection;

class FurnitureProduct extends Model
{
    use HasFactory;

    private const RECOMMENDATION_TYPE_MARKERS = [
        'Door' => 'door',
        'Table' => 'table',
        'Chair' => 'chair',
        'Bed' => 'bed',
        'Cabinet' => 'cabinet',
    ];

    protected $fillable = ['name', 'slug', 'category', 'description', 'wood_type', 'varnish_finish', 'style', 'price', 'width_cm', 'depth_cm', 'height_cm', 'dimensions_estimated', 'image_path', 'tags', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'tags' => 'array', 'width_cm' => 'decimal:2', 'depth_cm' => 'decimal:2', 'height_cm' => 'decimal:2', 'dimensions_estimated' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            $preserveEstimate = $product->exists
                && (bool) $product->getOriginal('dimensions_estimated')
                && ! $product->isDirty(['width_cm', 'depth_cm', 'height_cm']);

            $dimensions = \App\Services\FurnitureDimensionDefaults::resolve(
                (string) $product->name,
                (string) $product->category,
                [
                    'width_cm' => $product->width_cm,
                    'depth_cm' => $product->depth_cm,
                    'height_cm' => $product->height_cm,
                ],
            );

            $product->forceFill($dimensions);
            if ($preserveEstimate) {
                $product->dimensions_estimated = true;
            }
        });
    }
    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function views()
    {
        return $this->hasMany(FurnitureViewEvent::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function getImageUrlAttribute(): string
    {
        if (! $this->image_path) {
            return asset('images/furniture/default.svg');
        }
        if (str_starts_with($this->image_path, 'demo:')) {
            return asset('images/furniture/'.substr($this->image_path, 5));
        }
        if (str_starts_with($this->image_path, 'catalog:')) {
            return asset('images/catalog/'.substr($this->image_path, 8));
        }

        return asset('storage/'.$this->image_path);
    }

    public function recommendationType(): string
    {
        return static::recommendationTypeForCategory((string) $this->category);
    }

    public static function recommendationTypes(): Collection
    {
        $legacyTypes = static::active()->select('category')->distinct()->pluck('category')
            ->map(fn (string $category) => static::recommendationTypeForCategory($category))
            ->unique()->sort()->values();

        return collect(config('furnicraft.catalog_categories', []))->merge($legacyTypes)->unique()->values();
    }

    public static function catalogFilterOptions(): array
    {
        return [
            'categories' => collect(config('furnicraft.catalog_categories', [])),
            'styles' => static::active()->whereNotIn('style', ['Contemporary', 'Practical'])->distinct()->orderBy('style')->pluck('style'),
            'woods' => static::active()->whereNotIn('wood_type', ['Mangga', 'To be confirmed'])
                ->distinct()->pluck('wood_type')->merge(['Tanguile', 'Narra'])->unique()->sort()->values(),
            'finishes' => static::active()->distinct()->orderBy('varnish_finish')->pluck('varnish_finish'),
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeOrderByActivity(Builder $query): Builder
    {
        return $query->withCount(['views', 'favorites', 'reservations'])
            ->orderByRaw('(views_count + favorites_count * 3 + reservations_count * 5) DESC')
            ->orderBy('furniture_products.id');
    }

    public function scopeOfRecommendationType(Builder $query, string $type): Builder
    {
        // Catalog categories are exact choices; older saved broad types still work.
        if (in_array($type, config('furnicraft.catalog_categories', []), true)) {
            return $query->where($query->getModel()->qualifyColumn('category'), $type);
        }

        $categories = static::active()->select('category')->distinct()->pluck('category')
            ->filter(fn (string $category) => static::recommendationTypeForCategory($category) === $type)
            ->values()
            ->all();

        return $query->whereIn($query->getModel()->qualifyColumn('category'), $categories);
    }

    public function scopeMatchingPreference(Builder $query, CustomerPreference $preference): Builder
    {
        $query->whereBetween($query->getModel()->qualifyColumn('price'), [
            (float) $preference->budget_min,
            (float) $preference->budget_max,
        ]);

        foreach (['wood_type', 'varnish_finish', 'style'] as $field) {
            $expected = $preference->{$field};
            if (blank($expected)) {
                continue;
            }

            $query->whereRaw('LOWER(TRIM('.$query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn($field)).')) = ?', [mb_strtolower(trim((string) $expected))]);
        }

        return $query;
    }

    private static function recommendationTypeForCategory(string $category): string
    {
        $normalizedCategory = strtolower($category);

        foreach (static::RECOMMENDATION_TYPE_MARKERS as $type => $marker) {
            if (str_contains($normalizedCategory, $marker)) {
                return $type;
            }
        }

        return $category;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
