<?php

namespace App\Services;

use App\Models\{FurnitureProduct, RoomImage};
use Illuminate\Support\Arr;

class RoomPreviewIdentity
{
    public static function key(RoomImage $room, FurnitureProduct $product): string
    {
        $photos = app(RoomPhotoService::class);
        $roomHash = $photos->exists($room->image_path) ? hash('sha256', $photos->get($room->image_path)) : 'missing';
        $asset = (new RoomPreviewRasterService)->catalogImageBytes($product);
        return hash('sha256', json_encode([
            $roomHash, Arr::except($room->analysis ?? [], ['generated_previews', 'generated_preview_attempts']),
            $room->analysis_requested_at?->toISOString(), $room->gemini_consent_at?->toISOString(), $room->gemini_consent_version,
            $product->getAttributes(), $asset === null ? 'missing' : hash('sha256', $asset),
            config('roompreview.pipeline_version'), RoomPreviewV2Service::PLACEMENT_VERSION,
            AiProvider::name(), AiProvider::model(), config('services.gemini.image_model'),
        ], JSON_THROW_ON_ERROR));
    }
}
