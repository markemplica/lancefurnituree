@extends('layouts.app')
@section('title','Furniture catalog')
@section('body_class','catalog-browse-page')
@section('content')
<div class="catalog-page">
    <header class="page-heading catalog-page-heading">
        <div class="catalog-heading-copy">
            <h1>Furniture</h1>
            <p>Browse the Lance Furniture Shop catalog.</p>
        </div>
        <div class="search-wrap catalog-heading-search">
            <label class="form-label" for="catalog-search">Search furniture</label>
            <a class="catalog-planning-link" href="{{ route('preferences.edit') }}">Get recommendations <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></a>
            <div class="catalog-search-field">
                <svg class="catalog-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
                <input id="catalog-search" type="search" class="form-control" name="q" value="{{ request('q') }}" placeholder="Name, category, or style" autocomplete="off" data-live-search maxlength="255" aria-describedby="search-help" aria-controls="catalog-results" form="catalog-filter-form">
                <button class="catalog-search-clear" type="button" data-search-clear aria-label="Clear search" hidden><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
            </div>
            <span id="search-help" class="visually-hidden">Search checks product names, categories, style, wood, finish, descriptions, and tags. It adds matching products from related furniture categories while keeping unrelated categories out.</span>
            <div class="visually-hidden" role="status" aria-live="polite" data-search-status></div>
            <div id="catalog-search-suggestions" class="search-results catalog-search-results" data-search-results role="region" aria-label="Search suggestions" hidden></div>
        </div>
    </header>

    <div class="catalog-layout">
        <form id="catalog-filter-form" method="GET" action="{{ route('catalog.index') }}" class="filter-panel catalog-filters">
            <details class="catalog-filter-disclosure" data-catalog-filter-disclosure open>
                <summary class="catalog-filter-title">Filters</summary>
                <div class="catalog-filter-body row g-2 align-items-end">
                <div class="col-6 col-lg-2 catalog-filter-field"><label class="form-label" for="category">Category</label><select class="form-select" id="category" name="category"><option value="">All</option>@if($selectedCategory && !$catalogCategories->contains($selectedCategory))<option selected>{{ $selectedCategory }}</option>@endif @foreach($catalogCategories as $value)<option @selected($selectedCategory===$value)>{{ $value }}</option>@endforeach</select></div>
                <div class="col-6 col-lg-2 catalog-filter-field"><label class="form-label" for="style">Style</label><select class="form-select" id="style" name="style"><option value="">All</option>@foreach($options['styles'] as $value)<option @selected(request('style')===$value)>{{ $value }}</option>@endforeach</select></div>
                <div class="col-6 col-lg-2 catalog-filter-field"><label class="form-label" for="wood">Wood</label><select class="form-select" id="wood" name="wood_type"><option value="">Any</option>@foreach($options['woods'] as $value)<option @selected(request('wood_type')===$value)>{{ $value }}</option>@endforeach</select></div>
                <div class="col-6 col-lg-2 catalog-filter-field"><label class="form-label" for="finish">Finish</label><select class="form-select" id="finish" name="varnish_finish"><option value="">Any</option>@foreach($options['finishes'] as $value)<option @selected(request('varnish_finish')===$value)>{{ $value }}</option>@endforeach</select></div>
                <div class="col-6 col-lg-1 catalog-price-field">
                    <label class="form-label" for="min_price">Min budget (PHP)</label>
                    <input class="form-control @error('min_price') is-invalid @enderror" id="min_price" name="min_price" type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" value="{{ request('min_price') }}" @if($errors->has('min_price')) aria-invalid="true" @endif>
                    @error('min_price')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-lg-1 catalog-price-field">
                    <label class="form-label" for="max_price">Max budget (PHP)</label>
                    <input class="form-control @error('max_price') is-invalid @enderror" id="max_price" name="max_price" type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" value="{{ request('max_price') }}" @if($errors->has('max_price')) aria-invalid="true" @endif>
                    @error('max_price')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-lg-2 d-flex gap-2 catalog-filter-actions"><button type="submit" class="btn btn-dark flex-fill" data-filter-apply>Apply</button><a class="btn btn-outline-dark" href="{{ route('catalog.index') }}">Clear</a></div>
                </div>
            </details>
        </form>

        <section id="catalog-results" class="catalog-results" data-catalog-results aria-labelledby="catalog-results-heading">
            <div class="catalog-results-heading">
                <h2 id="catalog-results-heading" class="h5 mb-0" data-product-count aria-live="polite">{{ $products->total() }} {{ Str::plural('piece', $products->total()) }}</h2>
                <span class="text-muted small">Catalog listings do not confirm stock. Check prices, specifications, and availability with the shop.</span>
            </div>
            <div class="product-grid" data-product-grid @if($products->isEmpty()) hidden @endif>@foreach($products as $product)<x-product-card :product="$product" :saved="$favoriteIds->contains($product->id)" :eager="$loop->index < 4" />@endforeach</div>
            <div class="empty-state" data-live-search-empty hidden><h2>No furniture matches your search and filters.</h2><p>Try another search term or remove a filter.</p></div>
            <div class="empty-state" data-server-empty @if($products->isNotEmpty()) hidden @endif><h2>No furniture matches your search and filters.</h2><p>Try another search term or remove a filter.</p><a class="btn btn-outline-primary" href="{{ route('catalog.index') }}">Reset filters</a></div>
            <div data-catalog-pagination>{{ $products->links() }}</div>
        </section>
    </div>
</div>
@endsection

