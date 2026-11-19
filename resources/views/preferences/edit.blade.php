@extends('layouts.app')
@section('title','Plan your room')
@section('body_class','room-planning-page planner-photo-first')
@section('content')
@php($activeRoomId = (int) old('room_image_id', $selectedRoomId))

<div class="planning-workspace"><div class="planning-split">
<section id="room-preview" class="workflow-section" aria-labelledby="room-preview-heading">
    <div class="workflow-section-heading">
        <div>
            <h2 id="room-preview-heading">Room photo</h2>
            <p>A 2D preview is a visual estimate. Confirm measurements and clearance with the shop.</p>
        </div>
        <span class="workflow-state is-done" data-room-step-state @if(!$activeRoomId) hidden @endif>Room selected</span>
    </div>

    @unless($preferenceComplete)
        <div class="alert alert-warning fc-alert" data-step-one-warning>Complete Step 1 before uploading a room image.</div>
    @endunless

    <div class="row g-4">
        <div class="col-lg-5">
            <form method="POST" action="{{ route('room.store') }}" enctype="multipart/form-data" class="panel vstack gap-3">
                @csrf
                <input type="hidden" name="return_to" value="recommendations">
                <fieldset class="vstack gap-3" data-room-upload-fieldset @disabled(!$preferenceComplete)>
                    <div>
                        <label class="planner-image-input image-upload-zone" data-room-dropzone for="room_image">
                            <span data-room-input-placeholder>Click to upload or drag and drop</span>
                            <img data-room-input-preview alt="Selected room photo" hidden>
                            <input id="room_image" type="file" name="room_image" data-room-photo-input accept="image/jpeg,image/png,image/webp" required aria-label="Click to upload or drag and drop a room photo" aria-describedby="room-image-file-name">
                        </label>
                        <div id="room-image-file-name" class="form-text" data-room-file-name role="status" aria-live="polite"></div>
                        <div class="form-text">JPG, PNG, or WebP · Up to 8 MB.</div>
<details class="planner-photo-help"><summary>Photo tips and storage limits</summary>                        <div class="form-text">Save up to {{ config('privacy.room_photo_max_count') }} room photos, with {{ round(config('privacy.room_photo_max_bytes') / 1048576) }} MB total for originals. Photo times use Philippine time (UTC+8).</div>
                        <div class="form-text">Show the floor and nearby walls with a clear placement area. For doors, include the full doorway.</div>
</details>                        <div class="small mt-2" data-room-photo-quality role="status" aria-live="polite"></div>
                    </div>
                    <label class="form-check panel room-photo-consent mb-0"><input class="form-check-input" type="checkbox" name="gemini_consent" value="1" @checked(old('gemini_consent'))><span class="form-check-label">Allow FurniCraft to send this room photo to {{ \App\Services\AiProvider::label() }} for AI room analysis and placement checking. Previews and measurements are estimates. <a href="{{ route('privacy-policy') }}" target="_blank" rel="noopener">Privacy details</a></span></label>
                    <input type="hidden" name="ai_consent_provider" value="{{ \App\Services\AiProvider::name() }}">
                    @error('ai_consent_provider')<p class="text-danger small" role="alert">Refresh the page and review the current AI provider before uploading.</p>@enderror
                    @error('gemini_consent')<p class="text-danger small" role="alert">{{ $message }}</p>@enderror
                    <button class="btn btn-primary btn-lg" type="submit">Upload room photo</button>
                </fieldset>
            </form>
        </div>

        <div class="col-lg-7 planner-photo-library">
            <button type="button" class="planner-library-button" data-open-room-library>View your saved room</button>
        <dialog class="planner-room-dialog" data-room-library aria-labelledby="room-library-heading">
            <h2 id="room-library-heading">Your saved room photos</h2>
            <p class="small text-muted" data-room-selection-status aria-live="polite">Choose a room photo, then press OK to use it.</p>
            <div class="planner-room-list">
            @forelse($rooms as $room)
                @include('preferences._room-option')
            @empty
                <div class="empty-state"><h3>A room photo can come later.</h3><p>Start with your preferences. You can add a photo whenever you're ready.</p></div>
            @endforelse
            {{ $rooms->links() }}
            </div>
            <div class="planner-room-dialog-actions">
                <button type="button" class="btn btn-outline-dark" data-cancel-room-library>Cancel</button>
                <button type="button" class="btn btn-primary" data-confirm-room-library disabled>OK</button>
            </div>
        </dialog>
        </div>
    </div>

</section>
<div class="planner-settings"><section id="preference-form" class="workflow-section" aria-labelledby="preference-heading">
    <div class="workflow-section-heading">
        <div>
            <h1 id="preference-heading">Plan your room.</h1><p>Choose your furniture and budget. Add a room photo for an optional preview.</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="workflow-state is-done" data-preference-save-state @if(!$preferenceComplete) hidden @endif>Saved</span>

        </div>
    </div>

    <form method="POST" action="{{ route('preferences.update') }}" class="panel" data-preferences-form>
        @csrf
        <div class="alert fc-alert mb-4" role="status" aria-live="polite" data-preferences-status hidden></div>
        <div class="row g-3 planner-preference-fields">
            <div class="col-md-4"><label class="form-label" for="furniture_type">Furniture type</label><select class="form-select" id="furniture_type" name="furniture_type" required><option value="">Choose furniture</option>
                @if(filled($preference?->furniture_type) && !$furnitureTypes->contains($preference->furniture_type))
                    <option value="{{ $preference->furniture_type }}" @selected(old('furniture_type',$preference->furniture_type)===$preference->furniture_type)>{{ $preference->furniture_type }} (saved selection)</option>
                @endif
                @foreach($furnitureTypes as $type)<option value="{{ $type }}" @selected(old('furniture_type',$preference?->furniture_type)===$type)>{{ $type }}</option>@endforeach
            </select><div class="form-text">Recommendations will only include this category.</div></div>
            <?php
                $budgetRanges = [];
                for ($upper = 10000; $upper <= 100000; $upper += 10000) {
                    $lower = $upper === 10000 ? 1000 : $upper - 10000 + 0.01;
                    $budgetRanges[$lower.'-'.$upper] = 'PHP '.number_format($lower, 2).' – '.number_format($upper, 2);
                }
                $budgetRanges['100000.01-500000'] = 'PHP 100,000.01 – 500,000.00';
                $savedMinimum = old('budget_min', $preference?->budget_min);
                $savedMaximum = old('budget_max', $preference?->budget_max);
                $savedMinimum = is_numeric($savedMinimum) && $savedMinimum >= 0 && $savedMinimum <= 9999999999.99 ? $savedMinimum : null;
                $savedMaximum = is_numeric($savedMaximum) && $savedMaximum >= 0 && $savedMaximum <= 9999999999.99 ? $savedMaximum : null;
                $selectedBudget = old('budget_range', $savedMinimum !== null && $savedMaximum !== null ? (float) $savedMinimum.'-'.(float) $savedMaximum : '');
                $selectedBudget = is_string($selectedBudget) && strlen($selectedBudget) <= 64 ? $selectedBudget : '';
                if ($selectedBudget && !isset($budgetRanges[$selectedBudget]) && $savedMinimum !== null && $savedMaximum !== null) {
                    $budgetRanges[$selectedBudget] = 'PHP '.number_format($savedMinimum, 2).' – '.number_format($savedMaximum, 2).' (saved budget)';
                }
            ?>
            <div class="col-md-8"><label class="form-label" for="budget_range">Budget range</label><select class="form-select" id="budget_range" name="budget_range" required><option value="">Choose your budget</option>@foreach($budgetRanges as $value => $label)<option value="{{ $value }}" @selected($selectedBudget === $value)>{{ $label }}</option>@endforeach</select><div class="form-text">Preset budgets start at PHP 1,000.00.</div></div>
            @foreach([
                ['field' => 'style', 'label' => 'Furniture style', 'any' => 'Open to any style', 'values' => $styles],
                ['field' => 'wood_type', 'label' => 'Wood type', 'any' => 'Any wood', 'values' => $woodTypes],
                ['field' => 'varnish_finish', 'label' => 'Varnish finish', 'any' => 'Any finish', 'values' => $varnishFinishes],
            ] as $filter)
                @php($field = $filter['field'])
                <div class="col-md-4"><label class="form-label" for="{{ $field }}">{{ $filter['label'] }}</label><select class="form-select" id="{{ $field }}" name="{{ $field }}"><option value="">{{ $filter['any'] }}</option>
                    @if(filled($preference?->{$field}) && !$filter['values']->contains($preference->{$field}) && !($field === 'wood_type' && $preference->{$field} === 'To be confirmed'))
                        <option value="{{ $preference->{$field} }}" @selected(old($field,$preference->{$field})===$preference->{$field})>{{ $preference->{$field} }} (saved selection)</option>
                    @endif
                    @foreach($filter['values'] as $value)<option @selected(old($field,$preference?->{$field})===$value)>{{ $value }}</option>@endforeach
                </select></div>
            @endforeach

            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary btn-lg planning-save-button" type="submit" data-preferences-submit>{{ $preference ? 'Update preferences' : 'Save preferences and continue' }}</button></div>
        </div>
    </form>
</section>
    <form method="POST" action="{{ route('recommendations.store') }}" class="panel mt-4" id="recommendation-form">
        @csrf
        <input type="hidden" id="room_image_id" name="room_image_id" value="{{ $activeRoomId ?: '' }}">
        <div class="row g-3 align-items-center">
            <div class="col-lg">
                <h3 class="h4 mb-1">Your plan</h3>
                <p class="planning-summary-values"><span data-saved-furniture-type>{{ $preference?->furniture_type ?: 'Choose furniture' }}</span><span aria-hidden="true"> · </span><span data-saved-budget>{{ $preference?->budget_min !== null && $preference?->budget_max !== null ? 'PHP '.number_format($preference->budget_min, 2).' – '.number_format($preference->budget_max, 2) : 'Set your budget' }}</span></p>
                <p class="text-muted mb-0" data-selected-room-label>{{ $activeRoomId ? 'Saved room photo selected.' : 'No photo selected. Your saved preferences will be used.' }}</p>
            </div>
            <div class="col-lg-auto d-grid planning-final-actions">
                <button class="btn btn-outline-dark mb-2" type="button" data-skip-room @disabled(!$preferenceComplete)>Continue without room photo</button><button class="btn btn-primary btn-lg" type="submit" data-generate-recommendations @disabled(!$preferenceComplete)>Generate recommendations</button>
            </div>
        </div>
    </form><div class="planner-links"><a href="{{ route('recommendations.index') }}">Past recommendations</a><a href="{{ route('catalog.index') }}">Browse catalog</a></div></div></div></div>
@endsection
