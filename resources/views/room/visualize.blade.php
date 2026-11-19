@extends('layouts.app')
@section('title','Room comparison preview')
@section('content')
@php
    $selectedItem = $recommendation?->items->firstWhere('furniture_product_id', $selectedProduct?->id);
    $floorPolygon = data_get($roomImage->analysis, 'vision_geometry.floor_polygon', []);
    if (!is_array($floorPolygon) || count($floorPolygon) < 3) {
        $floorPolygon = data_get($roomImage->analysis, 'floor_polygon', []);
    }
    $hasDetectedPlacementSurface = is_array($floorPolygon) && count($floorPolygon) >= 3;
    $hasDetectedPlacementSurface = $hasDetectedPlacementSurface || (
        data_get($selectedPlacement, 'surface') === 'opening'
        && is_array(data_get($selectedPlacement, 'quad_percent'))
        && count(data_get($selectedPlacement, 'quad_percent')) === 4
    );
    $hasAiRoomFit = $roomImage->analysis_status === 'completed'
        && data_get($roomImage->analysis, 'gemini_used') === true
        && data_get($roomImage->analysis, 'vision_geometry.image_quality.usable') === true
        && $hasDetectedPlacementSurface
        && data_get($selectedPlacement, 'renderable', false) === true;
    $analysisRunning = in_array($roomImage->analysis_status, ['queued', 'processing', 'retrying'], true);
    $previewIsDoor = $selectedProduct && str_contains(strtolower($selectedProduct->recommendationType()), 'door');
    $hasPlacement = data_get($selectedPlacement, 'renderable', false) === true;
    $initialPlacementStatus = $hasPlacement
        ? 'Automatic visual placement'
        : (($analysisRunning && $roomImage->gemini_consent_at)
            ? ($previewIsDoor ? 'Analyzing doorway in your photo' : 'Analyzing room photo')
            : ($previewIsDoor ? 'No confirmed doorway available' : 'No validated placement available'));
    $previewRollout = (int) config('roompreview.rollout_percent', 0);
    $previewV2Enabled = config('roompreview.use_v2_pipeline') && ($previewRollout >= 100 || ($previewRollout > 0 && (hexdec(substr(hash('sha256', (string) auth()->id()), 0, 7)) % 100) < $previewRollout));
    $autoEnhanceEnabled = $roomImage->gemini_consent_at && $previewV2Enabled && config('services.gemini.image_enabled');
@endphp
<div class="room-compare-layout">
    <aside class="room-compare-story">
        <p class="section-kicker">Your room, reimagined</p>
        <h1>See <span data-preview-name>{{ $selectedProduct?->name ?? 'furniture' }}</span> in your space.</h1>
        <p class="room-compare-intro">{{ $hasAiRoomFit ? 'AI-guided visual estimate. Confirm dimensions with the shop.' : 'Estimated 2D fit from your room photo. Confirm dimensions with the shop.' }}</p>



        <section class="room-source" aria-labelledby="image-used-heading">
            <h2 id="image-used-heading" class="room-label">Image used</h2>
            <div class="room-source-card">
                <img src="{{ route('room-images.original', $roomImage) }}" alt="Uploaded room thumbnail">
                <div><span>Original room</span><strong>Selected room photo</strong><small>{{ $hasAiRoomFit ? 'AI-guided 2D placement' : ($hasPlacement ? 'Local 2D estimate' : 'Original photo shown') }}</small></div>
            </div>
        </section>

        <ol class="comparison-steps">
            <li><span>1</span><div><strong>Before</strong><small>Your uploaded room remains the base scene.</small></div></li>
            <li><span>2</span><div><strong>After</strong><small><span data-preview-name>{{ $selectedProduct?->name ?? 'The furniture' }}</span> appears here when a suitable placement area is found.</small></div></li>
        </ol>

        @if($selectedProduct)
        <article class="preview-product-card" data-preview-product-card>
            <img src="{{ $selectedProduct->image_url }}" alt="{{ $selectedProduct->name }}" data-preview-card-image>
            <div>
                <span class="room-label">{{ $recommendation ? 'Recommended product' : 'Selected product' }}</span>
                <strong data-preview-name>{{ $selectedProduct->name }}</strong>
                <small><span data-preview-score @if(!$selectedItem) hidden @endif>{{ $selectedItem ? number_format($selectedItem->total_score, 1).'% match · ' : '' }}</span>PHP <span data-preview-price>{{ number_format($selectedProduct->price, 2) }}</span></small>
                <a href="{{ route('catalog.show', $selectedProduct) }}" data-preview-product-link>View product details</a>
            </div>
        </article>
        @endif
    </aside>

    <section class="comparison-panel" aria-labelledby="comparison-heading">
        <header class="comparison-panel-head">
            <div><span class="room-label">2D placement preview</span><h2 id="comparison-heading">2D room preview</h2></div>
            <span class="preview-status"><i aria-hidden="true"></i> Planning preview</span>
        </header>

        @php($analysisLabel = ['completed' => 'AI analysis complete', 'queued' => 'Analysis queued', 'processing' => 'Analyzing room image', 'retrying' => 'AI unavailable · retrying', 'failed' => 'AI analysis needs attention'][$roomImage->analysis_status] ?? 'Local room guidance')
        <div class="alert {{ $roomImage->analysis_status === 'failed' ? 'alert-warning' : 'alert-light' }} fc-alert" role="status" aria-live="polite" data-room-analysis-watch="{{ route('room.analysis-status', $roomImage) }}" data-room-analysis-current="{{ $roomImage->analysis_status }}">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div><strong data-room-analysis-label>{{ $analysisLabel }}</strong><div class="small text-muted" data-room-analysis-message>{{ $roomImage->analysis_error ?: ($analysisRunning ? 'Analysis will update automatically.' : 'Placement details are below.') }}</div></div>
                @if(!$analysisRunning && !auth()->user()->isAdmin() && $roomImage->gemini_consent_at)
                    <form method="POST" action="{{ route('room.reanalyze', $roomImage) }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">Re-analyze room</button></form>
                @endif
            </div>
        </div>
<div class="placement-status" aria-live="polite">
            <div><span class="room-label">Selected item</span><strong data-placement-status>{{ $initialPlacementStatus }}</strong></div>
            <span data-placement-dimensions></span>
            <p data-placement-guidance class="small mb-0"></p>
        </div>
        @if($previewIsDoor)
            <div class="manual-door-placement" data-manual-door-placement>
                <button type="button" class="btn btn-sm btn-outline-dark" data-manual-door-mark aria-pressed="false">Mark doorway corners</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-manual-door-reset hidden>Reset marked corners</button>
                <p class="small text-muted mb-0" data-manual-door-instructions role="status" aria-live="polite">{{ $hasPlacement ? 'A door placement is available. Mark its four corners to adjust the fit manually.' : 'If AI cannot confirm the doorway, mark its four room-facing corners: top-left, top-right, bottom-right, then bottom-left.' }} This visual estimate stays in this browser.</p>
            </div>
        @endif

        <script type="application/json" data-initial-placement-quad>@json(data_get($selectedPlacement, 'quad_percent'))</script>

                <script type="application/json" data-preview-analysis>@json($roomImage->analysis ?? [])</script>
        <div class="room-preview-frame" data-room-preview
            data-room-id="{{ $roomImage->id }}"
            data-visual-scale-policy='@json(config("roompreview.visual_scale"))'
            data-product-id="{{ $selectedProduct?->id }}" data-product-name="{{ $selectedProduct?->name }}"
            data-product-width="{{ $selectedProduct?->width_cm }}" data-product-depth="{{ $selectedProduct?->depth_cm }}" data-product-height="{{ $selectedProduct?->height_cm }}" data-product-dimensions-estimated="{{ $selectedProduct?->dimensions_estimated ? 'true' : 'false' }}"
            data-product-type="{{ $selectedProduct?->recommendationType() }}" data-product-category="{{ $selectedProduct?->category }}"
            data-initial-x="{{ data_get($selectedPlacement,'x_percent',50) }}" data-initial-y="{{ data_get($selectedPlacement,'y_percent',86) }}"
            data-initial-width="{{ data_get($selectedPlacement,'max_width_percent',65) }}" data-initial-quad='@json(data_get($selectedPlacement,"quad_percent"))'
            data-confidence="{{ data_get($selectedPlacement,'confidence','low') }}" data-placement-source="{{ data_get($selectedPlacement,'source','safe-fallback') }}"
            data-placement-renderable="{{ data_get($selectedPlacement,'renderable',false) ? 'true' : 'false' }}" data-placement-surface="{{ data_get($selectedPlacement,'surface','') }}"
            data-placement-score="{{ data_get($selectedPlacement,'score',0) }}" data-placement-reason="{{ data_get($selectedPlacement,'reason','') }}"
            data-placement-flip="{{ data_get($selectedPlacement,'flip',1) === -1 ? 'true' : 'false' }}" data-placement-rotation="{{ data_get($selectedPlacement,'rotation_deg',0) }}" data-placement-perspective="{{ data_get($selectedPlacement,'perspective_deg',0) }}"
            data-placement-status="{{ $roomImage->analysis_status }}" data-consented="{{ $roomImage->gemini_consent_at ? 'true' : 'false' }}"
            data-preview-v2-url="{{ $selectedProduct ? route('room.preview-v2.store', $roomImage) : '' }}"
            data-preview-v2-enabled="{{ $previewV2Enabled ? 'true' : 'false' }}"
            data-auto-enhance="{{ $autoEnhanceEnabled ? 'true' : 'false' }}"
            data-verify-url="{{ $selectedProduct ? route('room.preview-verify', [$roomImage,$selectedProduct->id]) : '' }}"
            data-csrf="{{ csrf_token() }}">
            <img class="room-preview-source" src="{{ route('room-images.original', $roomImage) }}" alt="" data-preview-room>
            @if($selectedProduct)<img class="room-preview-source" src="{{ $selectedProduct->image_url }}" alt="" data-preview-product>@endif
            <canvas class="room-preview-canvas" data-room-preview-canvas role="img" aria-label="Automatically fitted furniture preview"></canvas>
            <div class="room-preview-loading" data-preview-loading role="status" aria-live="polite">Uploading · Analyzing room · Fitting furniture · Checking the result</div>
        </div>
        <div class="preview-detection-row" data-preview-detections aria-live="polite"></div>
        <div class="room-preview-actions">
            <button type="button" class="btn btn-primary" data-preview-download disabled>Download preview (PNG)</button>
            <a class="btn btn-outline-secondary" href="{{ route('preferences.edit',['room'=>$roomImage->id]) }}#room-preview">Upload another photo</a>
        </div>
            <p class="automatic-placement-note">Preview is an estimate, not a measurement. Verify actual dimensions with Lance Furniture Shop before reserving.</p>


        

        <div class="preview-footer-actions d-flex justify-content-end mt-3">
            @unless(auth()->user()->isAdmin())
                @if($selectedProduct)
                    <button type="button" class="btn btn-primary" data-reservation-open aria-haspopup="dialog" aria-controls="reservation-dialog">Reserve this furniture</button>
                @endif
                <a class="btn btn-outline-primary" href="{{ $recommendation ? route('recommendations.show', $recommendation) : route('preferences.edit', ['room' => $roomImage->id]).'#recommendation-form' }}">{{ $recommendation ? 'Back to recommendations' : 'Use room for recommendations' }}</a>
            @endunless
        </div>
    </section>
</div>
@if($selectedProduct && !auth()->user()->isAdmin())
<dialog id="reservation-dialog" class="reservation-dialog" aria-labelledby="reservation-dialog-title">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
        <h2 class="h4" id="reservation-dialog-title">Request a reservation</h2>
        <button type="button" class="btn-close" data-reservation-close aria-label="Close reservation form"></button>
    </div>
    <p class="fw-semibold">{{ $selectedProduct->name }}</p>
    <div data-reservation-message role="status" tabindex="-1" hidden></div>
    <p class="small text-muted">This sends a request for shop review. It does not hold the item or collect payment; the shop will confirm availability.</p>
    <form method="POST" action="{{ route('reservations.store') }}" class="vstack gap-3" data-reservation-form>
        @csrf
        <input type="hidden" name="furniture_product_id" value="{{ $selectedProduct->id }}">
        <div>
            <label class="form-label" for="visualize-preferred-visit-at">Preferred visit date and time <span class="text-muted">optional</span></label>
            <input class="form-control" type="datetime-local" id="visualize-preferred-visit-at" name="preferred_visit_at" min="{{ now()->format('Y-m-d\\TH:i') }}">
        </div>
        <div>
            <label class="form-label" for="visualize-reservation-notes">Notes <span class="text-muted">optional</span></label>
            <textarea class="form-control" id="visualize-reservation-notes" name="notes" rows="3" maxlength="1000" placeholder="Questions or preferred viewing schedule"></textarea>
        </div>
        <button class="btn btn-primary" type="submit">Send reservation request</button>
    </form>
    <div class="text-center mt-3" data-reservation-done hidden><button type="button" class="btn btn-primary">Done</button></div>
</dialog>
@endif
@endsection
