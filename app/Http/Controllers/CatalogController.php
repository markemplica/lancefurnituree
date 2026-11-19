<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\FurnitureProduct;
use App\Models\FurnitureViewEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    private const SEARCH_CANDIDATE_LIMIT = 100;
    private const SEARCH_RESULT_LIMIT = 8;

    public function favorites(Request $request)
    {
        $products = $request->user()->favorites()
            ->orderByPivot('created_at', 'desc')->paginate(12);

        return view('catalog.favorites', compact('products'));
    }

    public function index(Request $request)
    {
        $validator = $this->catalogValidator($request);
        if ($validator->fails()) {
            if ($request->expectsJson()) {
                throw new ValidationException($validator);
            }
            // Render a recoverable GET form; never redirect back to the invalid URL
            // or pass malformed arrays into Blade, pagination, or database bindings.
            $request->query->replace(array_diff_key($request->query(), $validator->errors()->messages()));
        }
        $query = FurnitureProduct::where('status', 'active')->withCount(['favorites', 'views', 'reservations']);
        foreach (['category', 'style', 'wood_type', 'varnish_finish'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->input('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->input('max_price'));
        }
        if ($request->filled('q')) {
            $this->applyCatalogSearch($query, trim((string) $request->input('q')));
        }
        $products = $query->orderBy('name')->paginate(16)->withQueryString();
        $options = FurnitureProduct::catalogFilterOptions();
        $favoriteIds = $request->user()?->favorites()->pluck('furniture_products.id') ?? collect();

        $data = compact('products', 'options', 'favoriteIds');
        if ($validator->errors()->isNotEmpty()) {
            $data['errors'] = (new ViewErrorBag)->put('default', $validator->errors());
        }

        return response()->view('catalog.index', $data, $validator->errors()->isEmpty() ? 200 : 422);
    }

    public function search(Request $request)
    {
        $validator = $this->catalogValidator($request, true);
        if ($validator->fails()) {
            return response()->json(['message' => 'Please check your search text.', 'errors' => $validator->errors()], 422);
        }
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }
        $simplified = preg_replace('/(.)\1+/u', '$1', $term);
        $query = FurnitureProduct::where('status', 'active');
        $this->applyCatalogSearch($query, $term);
        $products = $query->withCount(['views', 'favorites'])
            ->orderByRaw('CASE WHEN LOWER(furniture_products.name) LIKE ? THEN 1000 ELSE 0 END + views_count + (favorites_count * 3) DESC', [strtolower($simplified).'%'])
            ->orderBy('furniture_products.id')
            ->limit(self::SEARCH_CANDIDATE_LIMIT)
            ->get()
            ->take(self::SEARCH_RESULT_LIMIT)
            ->values();

        $favoriteIds = $request->user()?->favorites()->pluck('furniture_products.id') ?? collect();

        return response()->json($products->map(fn ($p) => [
            'name' => $p->name,
            'category' => $p->category,
            'price' => (float) $p->price,
            'image' => $p->image_url,
            'url' => route('catalog.show', $p),
            'wood_type' => $p->wood_type,
            'varnish_finish' => $p->varnish_finish,
            'style' => $p->style,
            'saved' => $favoriteIds->contains($p->id),
        ]));
    }

    private function applyCatalogSearch(Builder $query, string $term): void
    {
        $patterns = array_unique(['%'.$term.'%', '%'.preg_replace('/(.)\1+/u', '$1', $term).'%']);
        // Both routes share matching fields and typo handling. Keep OR matches
        // grouped so inactive products and unrelated filters cannot leak through.
        $query->where(function (Builder $matches) use ($patterns) {
            foreach (['name', 'category', 'style', 'description', 'tags'] as $field) {
                foreach ($patterns as $pattern) {
                    $matches->orWhere($field, 'like', $pattern);
                }
            }
        });
    }

    private function catalogValidator(Request $request, bool $searchOnly = false): \Illuminate\Contracts\Validation\Validator
    {
        $rules = ['q' => ['bail', 'nullable', 'string', 'max:255']];
        if (! $searchOnly) {
            foreach (['category', 'style', 'wood_type', 'varnish_finish'] as $field) {
                $rules[$field] = ['bail', 'nullable', 'string', 'max:255'];
            }
            foreach (['min_price', 'max_price'] as $field) {
                $rules[$field] = ['bail', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'];
            }
            $rules['page'] = ['bail', 'nullable', 'integer', 'min:1', 'max:1000000'];
        }
        $validator = Validator::make($request->query(), $rules, [], [
            'q' => 'search text', 'wood_type' => 'wood', 'varnish_finish' => 'finish',
            'min_price' => 'minimum price', 'max_price' => 'maximum price',
        ]);
        if (! $searchOnly) {
            $validator->after(function ($validator) use ($request) {
                if (! $validator->errors()->has('min_price') && ! $validator->errors()->has('max_price')
                    && $request->filled('min_price') && $request->filled('max_price')
                    && (float) $request->query('max_price') < (float) $request->query('min_price')) {
                    $validator->errors()->add('max_price', 'The maximum price must be at least the minimum price.');
                }
            });
        }

        return $validator;
    }

    public function show(Request $request, FurnitureProduct $furnitureProduct)
    {
        abort_if($furnitureProduct->status !== 'active', 404);
        if ($request->user()) {
            DB::transaction(function () use ($request, $furnitureProduct) {
                // Serialize this account's view writes so simultaneous refreshes
                // cannot both insert the same product/session event.
                $user = $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->first();
                if ($user) {
                    FurnitureViewEvent::firstOrCreate([
                        'user_id' => $user->id,
                        'furniture_product_id' => $furnitureProduct->id,
                        'session_key' => $request->session()->getId(),
                    ]);
                }
            }, 3);
        }
        $isFavorite = $request->user() && Favorite::where('user_id', $request->user()->id)->where('furniture_product_id', $furnitureProduct->id)->exists();

        return view('catalog.show', ['product' => $furnitureProduct, 'isFavorite' => $isFavorite]);
    }

    public function favorite(Request $request, FurnitureProduct $furnitureProduct)
    {
        $favorite = Favorite::where('user_id', $request->user()->id)->where('furniture_product_id', $furnitureProduct->id)->first();
        abort_if(! $favorite && $furnitureProduct->status !== 'active', 404);

        if ($favorite) {
            $favorite->delete();
            $saved = false;
        } else {
            Favorite::create(['user_id' => $request->user()->id, 'furniture_product_id' => $furnitureProduct->id]);
            $saved = true;
        }

        return $request->expectsJson() ? response()->json(['saved' => $saved]) : back()->with('success', $saved ? 'Saved to your favorites.' : 'Removed from your favorites.');
    }
}
