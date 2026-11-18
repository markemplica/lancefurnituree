@props(['id', 'name', 'maxMb' => 8, 'required' => false])
<label class="image-upload-zone" for="{{ $id }}" data-basic-image-dropzone data-max-bytes="{{ $maxMb * 1024 * 1024 }}">
    <span>Click to upload or drag and drop</span>
    <input class="form-control" type="file" id="{{ $id }}" name="{{ $name }}" accept="image/jpeg,image/png,image/webp" @required($required) aria-describedby="{{ $id }}-upload-status">
</label>
<p class="form-text mb-0" id="{{ $id }}-upload-status" data-basic-image-status role="status" aria-live="polite"></p>
