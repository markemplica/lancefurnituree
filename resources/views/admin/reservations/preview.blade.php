@extends('layouts.app')
@section('title','Reservation 2D preview')
@section('content')
<header class="page-heading"><div><p class="section-kicker">{{ $reservation->reference }} · {{ $reservation->user->name }}</p><h1>{{ $product->name }} · 2D preview</h1><p>Linked to the customer's reserved furniture and room photo. Confirm actual measurements and clearance with the customer.</p></div><a class="btn btn-outline-dark" href="{{ route('admin.reservations.index') }}">Back to reservations</a></header>
<section class="panel comparison-panel reservation-preview-panel">
@if($saved)
    <p class="small text-muted">Saved generated preview for this room and furniture. This is the latest saved preview, rather than a snapshot locked at reservation time.</p>
    <img class="reservation-saved-preview" src="{{ route('admin.reservations.preview-image', $reservation) }}" alt="Saved room preview with {{ $product->name }}">
@elseif($available)
    <p class="small text-muted">No saved preview image is available. This local 2D estimate is reconstructed from the linked room analysis and reserved furniture; it may differ from the customer's earlier preview.</p>
    <p class="small"><span data-placement-status>Preparing local preview</span> <span data-placement-dimensions></span></p>
    @if(str_contains(strtolower($product->recommendationType()), 'door') && !data_get($placement, 'renderable', false))
        <div class="manual-door-placement" data-manual-door-placement>
            <button type="button" class="btn btn-sm btn-outline-dark" data-manual-door-mark aria-pressed="false">Mark doorway corners</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-manual-door-reset hidden>Reset marked corners</button>
            <p class="small text-muted mb-0" data-manual-door-instructions role="status" aria-live="polite">No valid doorway corners were saved with this analysis. In the preview below, click top-left, top-right, bottom-right, then bottom-left. This estimate is temporary in this browser; confirm the doorway measurements with the customer.</p>
        </div>
    @endif
    <div class="room-preview-frame" data-room-preview data-preview-v2-enabled="false" data-consented="false" data-placement-status="fallback"
        data-room-id="{{ $room->id }}"
        data-visual-scale-policy='@json(config("roompreview.visual_scale"))'
        data-product-id="{{ $product->id }}" data-product-name="{{ $product->name }}" data-product-type="{{ $product->recommendationType() }}" data-product-category="{{ $product->category }}"
        data-product-width="{{ $product->width_cm }}" data-product-depth="{{ $product->depth_cm }}" data-product-height="{{ $product->height_cm }}"
        data-initial-x="{{ data_get($placement,'x_percent',50) }}" data-initial-y="{{ data_get($placement,'y_percent',86) }}" data-initial-width="{{ data_get($placement,'max_width_percent',65) }}"
        data-initial-quad='@json(data_get($placement,"quad_percent"))' data-confidence="{{ data_get($placement,'confidence','low') }}"
        data-placement-renderable="{{ data_get($placement,'renderable',false) ? 'true' : 'false' }}" data-placement-surface="{{ data_get($placement,'surface','') }}"
        data-placement-flip="{{ data_get($placement,'flip',1) === -1 ? 'true' : 'false' }}" data-placement-rotation="{{ data_get($placement,'rotation_deg',0) }}" data-placement-perspective="{{ data_get($placement,'perspective_deg',0) }}">
        <script type="application/json" data-preview-analysis>@json($room->analysis ?? [])</script>
        <img class="room-preview-source" src="{{ route('room-images.original',$room) }}" alt="" data-preview-room>
        <img class="room-preview-source" src="{{ $product->image_url }}" alt="" data-preview-product>
        <canvas class="room-preview-canvas" data-room-preview-canvas role="img" aria-label="Reconstructed room preview with reserved furniture"></canvas>
        <div data-preview-loading class="room-preview-loading" role="status">Preparing local 2D preview…</div>
    </div>
    <p class="small mt-2 mb-0" data-placement-guidance role="status"></p>
@else
    <p class="mb-0">The customer’s room photo is no longer available. A preview cannot be shown.</p>
@endif
</section>
@endsection
