@php
    $editing = isset($product);
    $recordLayout = $recordLayout ?? false;
@endphp
@if($recordLayout)
    <div class="furniture-record-heading">
        <h2>Furniture details</h2>
        <span>{{ $editing ? 'Edit catalog entry' : 'New catalog entry' }}</span>
    </div>
    <div class="furniture-record-columns">
        <div class="furniture-record-column">
            @include('admin.products._form-section', ['section' => 'identity'])
            @include('admin.products._form-section', ['section' => 'image'])
        </div>
        <div class="furniture-record-column">
            @include('admin.products._form-section', ['section' => 'materials'])
            @include('admin.products._form-section', ['section' => 'dimensions'])
            @include('admin.products._form-section', ['section' => 'tags'])
        </div>
    </div>
    @include('admin.products._form-section', ['section' => 'availability'])
@else
    <p class="furniture-required-note">Fields marked <span aria-hidden="true">*</span> are required.</p>
    <div class="furniture-editor-grid">
        <div class="furniture-editor-main">
            @foreach(['identity', 'materials', 'dimensions', 'tags'] as $section)
                @include('admin.products._form-section', ['section' => $section])
            @endforeach
        </div>
        <aside class="furniture-editor-sidebar" aria-label="Price, availability, and image">
            @include('admin.products._form-section', ['section' => 'availability'])
            @include('admin.products._form-section', ['section' => 'image'])
        </aside>
    </div>
@endif
<div class="col-12 d-flex justify-content-end gap-2 furniture-editor-actions">
    @if($recordLayout)<p class="furniture-required-note">Fields marked <span aria-hidden="true">*</span> are required.</p>@endif
    <a class="btn btn-outline-dark" href="{{ route('admin.products.index') }}">Cancel</a>
    <button type="submit" class="btn btn-primary btn-lg">{{ $editing ? 'Save changes' : 'Add furniture' }}</button>
</div>
