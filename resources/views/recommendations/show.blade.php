@extends('layouts.app')
@section('title','Recommended furniture')
@section('body_class','ai-matches-page')
@section('content')
@php
    $previewItem = $recommendation->items->first();
    $previewProduct = $previewItem?->product;
    $room = $recommendation->roomImage;
    $previewPlacement = $previewProduct ? ($placements->get($previewProduct->id) ?? []) : [];
    $previewAnalysisRunning = $room && in_array($room->analysis_status, ['queued', 'processing', 'retrying'], true);
    $previewIsDoor = $previewProduct && str_contains(strtolower($previewProduct->recommendationType()), 'door');
    $initialPlacementStatus = data_get($previewPlacement, 'renderable', false)
        ? 'Automatic image fit'
        : (($previewAnalysisRunning && $room->gemini_consent_at)
            ? ($previewIsDoor ? 'Analyzing doorway in your photo' : 'Analyzing room photo')
            : ($previewIsDoor ? 'No confirmed doorway available' : 'No validated placement available'));
    $previewRollout = (int) config('roompreview.rollout_percent', 0);
    $previewV2Enabled = config('roompreview.use_v2_pipeline') && ($previewRollout >= 100 || ($previewRollout > 0 && (hexdec(substr(hash('sha256', (string) auth()->id()), 0, 7)) % 100) < $previewRollout));
@endphp

<header class="page-heading ai-matches-heading">
    <div>
        <p class="section-kicker">Step 3 · Your ranked matches</p>
        <h1>{{ ucfirst(strtolower($recommendation->furniture_type ?: 'Furniture')) }} options selected for your room.</h1>
    </div>
    <a class="btn btn-outline-dark" href="{{ route('preferences.edit') }}">Adjust room &amp; preferences</a>
</header>

<p class="small text-muted">Current filters: PHP {{ number_format($recommendation->preference->budget_min, 2) }}–{{ number_format($recommendation->preference->budget_max, 2) }} · {{ $recommendation->preference->wood_type ?: 'Any wood' }} · {{ $recommendation->preference->style ?: 'Any style' }} · {{ $recommendation->preference->varnish_finish ?: 'Any finish' }}</p><x-workflow-progress :step="3" />
<p class="small text-muted">Summary created locally by FurniCraft from your saved preferences and catalog matches.</p>

<div class="ai-matches-dashboard {{ !$previewProduct ? 'has-no-exact-matches' : '' }}">
@if(!$previewProduct)
<section class="panel no-match-panel">
<header class="no-match-intro"><span class="section-kicker">Your preferences</span><h2>No exact matches</h2><p>No furniture matches all your preferences. Here are the closest available alternatives, with the differences shown below.</p><a class="btn btn-outline-dark btn-sm" href="{{ route('preferences.edit') }}">Adjust preferences</a></header>
<div class="closest-suggestions">
@if($suggestions->isNotEmpty())
<h3 class="h5">Closest suggestions</h3>
<p class="small text-muted">These alternatives do not meet every preference. Review the differences before choosing.</p>
<div class="closest-suggestions-grid">
@foreach($suggestions as $suggestion)
<article class="closest-suggestion-card">
<a href="{{ route('catalog.show', $suggestion['product']) }}"><img src="{{ $suggestion['product']->image_url }}" alt="{{ $suggestion['product']->name }}"></a>
<div class="closest-suggestion-body"><span class="badge text-bg-light mb-2">Alternative · not an exact match</span>
<h4 class="h6">{{ $suggestion['product']->name }}</h4><strong>PHP {{ number_format($suggestion['product']->price, 2) }}</strong>
<ul class="small mt-2">@foreach($suggestion['differences'] as $difference)<li>{{ $difference }}</li>@endforeach</ul>
<div class="d-flex flex-wrap gap-2">
<a class="btn btn-outline-dark btn-sm" href="{{ route('catalog.show', $suggestion['product']) }}">View product</a>
@if($room)
<a class="btn btn-primary btn-sm" href="{{ route('room.visualize', ['roomImage' => $room, 'product' => $suggestion['product']->id, 'recommendation' => $recommendation->id]) }}">2D preview</a>
@else
<a class="btn btn-primary btn-sm" href="{{ route('preferences.edit') }}#room-preview">Upload room for 2D preview</a>
@endif
</div>
</div></article>
@endforeach
</div>
@else
<p>No active alternatives are available for this furniture type.</p>
@endif
</div>
 </section>
@endif
@if($room && $previewProduct)
    <section class="comparison-panel recommendation-inline-preview" id="ai-room-preview" aria-labelledby="inline-preview-heading">
        <aside class="inline-preview-story">
            <p class="section-kicker">Your room, reimagined</p>
            <h2 id="inline-preview-heading">See <span data-inline-preview-name>{{ $previewProduct->name }}</span> in your space.</h2>
            <p class="inline-preview-intro">The selected furniture is automatically positioned and scaled using the room photo, visible floor area, and perspective.</p>
            <p class="small text-muted">The exact catalog image is placed automatically in a 2D preview. AI visual checking is available after consent and a valid placement.</p>

            <div class="room-source-card inline-room-source">
                <img src="{{ route('room-images.original', $room) }}" alt="Uploaded room thumbnail">
                <div><span>Original room</span><strong>Selected room photo</strong><small>@if($room->room_width_m && $room->room_depth_m){{ data_get($room->analysis, 'dimension_confidence') === 'customer-provided' ? 'Previously entered dimensions' : 'Photo estimate' }}: {{ $room->room_width_m }} × {{ $room->room_depth_m }} m @else Photo dimensions unavailable @endif</small></div>
            </div>

            <ol class="comparison-steps inline-preview-steps">
                <li><span>1</span><div><strong>Before</strong><small>Your uploaded room remains the base scene.</small></div></li>
                <li><span>2</span><div><strong>After</strong><small>The selected product is placed only when a usable room zone is available.</small></div></li>
            </ol>

            <article class="preview-product-card inline-selected-product">
                <img src="{{ $previewProduct->image_url }}" alt="{{ $previewProduct->name }}" data-inline-preview-card-image>
                <div>
                    <span class="room-label">Recommended product</span>
                    <strong data-inline-preview-name>{{ $previewProduct->name }}</strong>
                    <small><span data-inline-preview-score>{{ number_format($previewItem->total_score,0) }}% match</span> · PHP <span data-inline-preview-price>{{ number_format($previewProduct->price,2) }}</span></small>
                    <a href="{{ route('catalog.show',$previewProduct) }}" data-inline-preview-link>View product details</a>
                </div>
            </article>

        </aside>

        <div class="inline-preview-visual">
            <header class="comparison-panel-head">
                <div>
                    <span class="room-label">2D placement preview</span>
                    <h3>Automatic 2D room preview</h3>
                </div>
                <span class="preview-status"><i aria-hidden="true"></i> Planning preview</span>
            </header>

            <div data-room-analysis-watch="{{ route('room.analysis-status', $room) }}" data-room-analysis-current="{{ $room->analysis_status }}">
                <span class="small text-muted" data-room-analysis-message></span>
            </div>
            <div class="placement-status" aria-live="polite">
                <div><span class="room-label">Furniture selected for this preview</span><strong data-inline-preview-name>{{ $previewProduct->name }}</strong></div>
                <span><span data-placement-status>{{ $initialPlacementStatus }}</span><br><span data-placement-dimensions></span></span>
                <p data-placement-guidance class="small mb-0"></p>
            </div>



            <script type="application/json" data-preview-analysis>@json($room->analysis ?? [])</script>
            <div class="room-preview-frame room-comparison" data-room-preview role="slider" tabindex="0" aria-label="Before and after preview. Use the arrow keys to move the divider." aria-valuemin="0" aria-valuemax="100" aria-valuenow="50" data-split="50"
                data-visual-scale-policy='@json(config("roompreview.visual_scale"))'
                data-product-id="{{ $previewProduct->id }}" data-product-name="{{ $previewProduct->name }}"
                data-product-width="{{ $previewProduct->width_cm }}" data-product-depth="{{ $previewProduct->depth_cm }}" data-product-height="{{ $previewProduct->height_cm }}" data-product-dimensions-estimated="{{ $previewProduct->dimensions_estimated ? 'true' : 'false' }}"
                data-product-type="{{ $previewProduct->recommendationType() }}" data-product-category="{{ $previewProduct->category }}" data-room-id="{{ $room->id }}"
                data-initial-x="{{ data_get($previewPlacement,'x_percent',50) }}" data-initial-y="{{ data_get($previewPlacement,'y_percent',86) }}"
                data-initial-width="{{ data_get($previewPlacement,'max_width_percent',65) }}" data-initial-quad='@json(data_get($previewPlacement,"quad_percent"))'
                data-confidence="{{ data_get($previewPlacement,'confidence','low') }}" data-placement-source="{{ data_get($previewPlacement,'source','safe-fallback') }}"
                data-placement-renderable="{{ data_get($previewPlacement,'renderable',false) ? 'true' : 'false' }}" data-placement-surface="{{ data_get($previewPlacement,'surface','') }}"
                data-placement-score="{{ data_get($previewPlacement,'score',0) }}" data-placement-reason="{{ data_get($previewPlacement,'reason','') }}"
                data-placement-flip="{{ data_get($previewPlacement,'flip',1) === -1 ? 'true' : 'false' }}" data-placement-rotation="{{ data_get($previewPlacement,'rotation_deg',0) }}" data-placement-perspective="{{ data_get($previewPlacement,'perspective_deg',0) }}"
                data-placement-status="{{ $room->analysis_status }}" data-consented="{{ $room->gemini_consent_at ? 'true' : 'false' }}"
                data-preview-v2-url="{{ route('room.preview-v2.store', $room) }}"
                data-preview-v2-enabled="{{ $previewV2Enabled ? 'true' : 'false' }}"
                data-verify-url="{{ route('room.preview-verify', [$room,$previewProduct->id]) }}" data-csrf="{{ csrf_token() }}">
                <img class="comparison-room-base" src="{{ route('room-images.original', $room) }}" alt="Before: original room">
                <img class="room-preview-source" src="{{ route('room-images.original', $room) }}" alt="" data-preview-room>
                <img class="room-preview-source" src="{{ $previewProduct->image_url }}" alt="" data-preview-product>
                <div class="comparison-result" data-comparison-result>
                    <canvas class="room-preview-canvas" data-room-preview-canvas role="img" aria-label="After: room with automatically fitted furniture"></canvas>
                </div>
                <div class="comparison-divider" data-comparison-divider aria-hidden="true"></div>
                <span class="comparison-tag comparison-tag-original" aria-hidden="true">Before</span>
                <span class="comparison-tag comparison-tag-result" aria-hidden="true">After</span>
                <div class="room-preview-loading" data-preview-loading role="status" aria-live="polite">Uploading · Analyzing room · Fitting furniture · Checking the result</div>
            </div>
            <div class="preview-detection-row" data-preview-detections aria-live="polite"></div>
            @unless($room->gemini_consent_at)
            <p class="small text-muted">Automatic visual sizing is active. For AI room analysis, upload the photo again and allow {{ \App\Services\AiProvider::label() }} to analyze it.</p>
            @endunless
            @if($previewIsDoor)
            <div class="manual-door-placement" data-manual-door-placement>
                <button type="button" class="btn btn-sm btn-outline-dark" data-manual-door-mark aria-pressed="false">Mark doorway corners</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-manual-door-reset hidden>Reset marked corners</button>
                <p class="small text-muted mb-0" data-manual-door-instructions role="status" aria-live="polite">If the automatic fit is unavailable, mark the doorway on the photo. This visual estimate stays in this browser.</p>
            </div>
            @endif
            <div class="room-preview-actions">
                <button type="button" class="btn btn-outline-primary" data-reservation-open aria-haspopup="dialog" aria-controls="reservation-dialog">Reserve this furniture</button>
                <button type="button" class="btn btn-primary" data-preview-download disabled>Download preview (PNG)</button>
            </div>
            <p class="automatic-placement-note">Preview is an estimate, not a measurement. Verify actual dimensions with Lance Furniture Shop before reserving.</p>
        </div>
    </section>
    <dialog id="reservation-dialog" class="reservation-dialog" aria-labelledby="reservation-dialog-title">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
            <h2 class="h4" id="reservation-dialog-title">Request a reservation</h2>
            <button type="button" class="btn-close" data-reservation-close aria-label="Close reservation form"></button>
        </div>
        <p class="fw-semibold" data-reservation-product-name>{{ $previewProduct->name }}</p>
        <div data-reservation-message role="status" tabindex="-1" hidden></div>
        <p class="small text-muted">This sends a request for shop review. It does not hold the item or collect payment; the shop will confirm availability.</p>
        <form method="POST" action="{{ route('reservations.store') }}" class="vstack gap-3" data-reservation-form>
            @csrf
            <input type="hidden" name="furniture_product_id" value="{{ $previewProduct->id }}" data-reservation-product-id>
            <input type="hidden" name="recommendation_id" value="{{ $recommendation->id }}">
            <div>
                <label class="form-label" for="recommendation-reservation-visit-at">Preferred visit date and time <span class="text-muted">optional</span></label>
                <input class="form-control" type="datetime-local" id="recommendation-reservation-visit-at" name="preferred_visit_at" min="{{ now()->format('Y-m-d\\TH:i') }}">
            </div>
            <div>
                <label class="form-label" for="recommendation-reservation-notes">Notes <span class="text-muted">optional</span></label>
                <textarea class="form-control" id="recommendation-reservation-notes" name="notes" rows="3" maxlength="1000" placeholder="Questions or preferred viewing schedule"></textarea>
            </div>
            <button class="btn btn-primary" type="submit">Send reservation request</button>
        </form>
        <div class="text-center mt-3" data-reservation-done hidden><button type="button" class="btn btn-primary">Done</button></div>
    </dialog>
@endif

    @if($recommendation->items->isNotEmpty())
    <section class="ai-matches-results" aria-labelledby="matches-heading">
        <h2 id="matches-heading" class="h4 mb-3">Ranked matches</h2>
        <div class="fc-card overflow-hidden">
            @foreach($recommendation->items as $item)
                @php($itemPlacement = $placements->get($item->product->id) ?? [])
                <article class="recommendation-item">
                    <a href="{{ route('catalog.show',$item->product) }}"><img src="{{ $item->product->image_url }}" alt="{{ $item->product->name }}"></a>
                    <div>
                        <p class="product-meta mb-1">#{{ $item->position }} · {{ $item->product->category }}</p>
                        <h3 class="h4"><a class="text-reset" href="{{ route('catalog.show',$item->product) }}">{{ $item->product->name }}</a></h3>
                        <p class="recommendation-reason">{{ $item->explanation }}</p>
                        <p class="product-meta">{{ $item->product->wood_type }} · {{ $item->product->varnish_finish }} · {{ number_format((float) $item->product->width_cm, 0) }} × {{ number_format((float) $item->product->depth_cm, 0) }} cm @if($item->product->dimensions_estimated)· standard estimate @endif</p>
                        <strong>PHP {{ number_format($item->product->price,2) }}</strong>
                        @if($room)
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" data-recommendation-preview
                                data-product-id="{{ $item->product->id }}" data-product-name="{{ $item->product->name }}"
                                data-product-image="{{ $item->product->image_url }}" data-product-width="{{ $item->product->width_cm }}"
                                data-product-depth="{{ $item->product->depth_cm }}" data-product-height="{{ $item->product->height_cm }}" data-product-dimensions-estimated="{{ $item->product->dimensions_estimated ? 'true' : 'false' }}"
                                data-product-type="{{ $item->product->recommendationType() }}" data-product-category="{{ $item->product->category }}"
                                data-product-score="{{ number_format($item->total_score,0) }}" data-product-price="{{ number_format($item->product->price,2) }}"
                                data-product-url="{{ route('catalog.show',$item->product) }}"
                                data-verify-url="{{ route('room.preview-verify', [$room,$item->product->id]) }}"
                                data-placement-x="{{ data_get($itemPlacement,'x_percent',50) }}" data-placement-y="{{ data_get($itemPlacement,'y_percent',86) }}"
                                data-placement-width="{{ data_get($itemPlacement,'max_width_percent',65) }}" data-placement-quad='@json(data_get($itemPlacement,"quad_percent"))'
                                data-placement-confidence="{{ data_get($itemPlacement,'confidence','low') }}" data-placement-source="{{ data_get($itemPlacement,'source','safe-fallback') }}"
                                data-placement-renderable="{{ data_get($itemPlacement,'renderable',false) ? 'true' : 'false' }}" data-placement-surface="{{ data_get($itemPlacement,'surface','') }}"
                                data-placement-score="{{ data_get($itemPlacement,'score',0) }}" data-placement-reason="{{ data_get($itemPlacement,'reason','') }}"
                                data-placement-flip="{{ data_get($itemPlacement,'flip',1) === -1 ? 'true' : 'false' }}" data-placement-rotation="{{ data_get($itemPlacement,'rotation_deg',0) }}" data-placement-perspective="{{ data_get($itemPlacement,'perspective_deg',0) }}"
                                aria-pressed="{{ $previewProduct->id === $item->product->id ? 'true' : 'false' }}">
                                {{ $previewProduct->id === $item->product->id ? 'Selected' : 'Preview in room' }}
                            </button>
                        @endif
                    </div>
                    <div>
                        <div class="score">{{ number_format($item->total_score,0) }}%</div>
                        <form method="POST" action="{{ route('reservations.store') }}" class="mt-3 vstack gap-2">
                            @csrf
                            <input type="hidden" name="furniture_product_id" value="{{ $item->product->id }}">
                            <input type="hidden" name="recommendation_id" value="{{ $recommendation->id }}">
                            <input class="form-control form-control-sm" type="datetime-local" name="preferred_visit_at" min="{{ now()->format('Y-m-d\\TH:i') }}" aria-label="Preferred visit date and time">
                            @include('reservations._customization-fields', ['formSuffix' => 'recommendation-'.$item->product->id, 'floating' => true])<button class="btn btn-sm btn-primary">Reserve</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
    @endif
</div>
@include('recommendations._floating-chat')
@endsection
