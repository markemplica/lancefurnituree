<?php

namespace App\Jobs;

use App\Models\FurnitureProduct;
use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use App\Services\GeminiService;
use App\Services\AiTelemetry;
use App\Services\PrivacyAudit;
use App\Services\RoomPhotoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Context;

class GenerateRoomPreview implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 540;

    public int $uniqueFor = 600;

    public function __construct(public int $roomImageId, public int $productId) {}

    public function uniqueId(): string
    {
        return $this->roomImageId.':'.$this->productId;
    }

    public function backoff(): array
    {
        return [30];
    }

    public function handle(GeminiService $gemini, RoomPhotoService $photos, PrivacyAudit $audit, AiTelemetry $telemetry): void
    {
        $room = RoomImage::find($this->roomImageId);
        $product = FurnitureProduct::find($this->productId);
        $generation = RoomPreviewGeneration::where('room_image_id', $this->roomImageId)
            ->where('furniture_product_id', $this->productId)->first();

        if (! $room || ! $product || ! $generation) return;
        if (filled($generation->cache_key)) return; // A legacy delivery never owns a v2 attempt.
        if (! in_array($generation->status, ['queued', 'retrying'], true)) return;


        if (! \App\Services\AiProvider::hasPhotoConsent($room)) {
            $generation->update(['status' => 'cancelled', 'message' => 'AI processing is disabled for this photo.']);
            return;
        }

        if (RoomPreviewGeneration::whereKey($generation->id)->whereNull('cache_key')->whereIn('status', ['queued', 'retrying'])
            ->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'message' => null]) !== 1) return;
        $previousAction = Context::getHidden('ai_action_key');
        $previousActor = Context::getHidden('ai_user_id');

        try {
            Context::addHidden('ai_action_key', 'legacy-preview:'.$generation->id.':'.$generation->created_at?->toISOString());
            Context::addHidden('ai_user_id', $room->user_id);
            Context::addHidden('ai_photo_transfer', ['room_id'=>$room->id,'user_id'=>$room->user_id,'purpose'=>'furnished_preview']);
            $result = $gemini->furnishRoom($room->image_path, $product, true);

            if (! $result['used'] || ! filled($result['bytes'] ?? null)) {
                $generation->update(['status' => 'fallback', 'message' => 'Gemini could not create the image. The local 2D placement preview is shown instead.']);
                return;
            }

            DB::transaction(function () use ($room, $product, $generation, $result, $photos) {
                $current = RoomImage::whereKey($room->id)->lockForUpdate()->first();
                if (! $current || ! $current->gemini_consent_at) {
                    $generation->update(['status' => 'cancelled', 'message' => 'AI processing was disabled before the image could be saved.']);
                    return;
                }

                $extension = match ($result['mime_type']) {
                    'image/jpeg', 'image/jpg' => 'jpg',
                    'image/webp' => 'webp',
                    default => 'png',
                };
                $path = 'rooms/'.$current->user_id.'/previews/room-'.$current->id.'-product-'.$product->id.'-'.$product->updated_at->timestamp.'.'.$extension;
                $photos->storeGenerated($path, $result['bytes']);

                $analysis = $current->analysis ?? [];
                $analysis['generated_previews'][(string) $product->id] = [
                    'path' => $path,
                    'gemini_used' => true,
                    'generated_at' => now()->toIso8601String(),
                ];
                $current->update(['analysis' => $analysis]);
                $generation->update(['status' => 'completed', 'message' => 'Generated room preview is ready.']);
            });
        } catch (Throwable $exception) {
            if ($this->attempts() >= $this->tries) {
                $telemetry->record('image', 'furnished_preview', 'fallback', fallbackUsed: true, failureCode: $telemetry->failureCode($exception));
            }
            $generation->update([
                'status' => $this->attempts() < $this->tries ? 'retrying' : 'failed',
                'message' => $this->attempts() < $this->tries
                    ? 'Gemini is temporarily unavailable. FurniCraft will retry automatically.'
                    : 'Gemini could not create the image. The local 2D placement preview is shown instead.',
            ]);
            throw $exception;
        } finally {
            Context::forgetHidden('ai_photo_transfer');
            $previousAction === null ? Context::forgetHidden('ai_action_key') : Context::addHidden('ai_action_key', $previousAction);
            $previousActor === null ? Context::forgetHidden('ai_user_id') : Context::addHidden('ai_user_id', $previousActor);
        }
    }

    public function failed(?Throwable $exception): void
    {
        RoomPreviewGeneration::where('room_image_id', $this->roomImageId)
            ->where('furniture_product_id', $this->productId)
            ->whereNull('cache_key')->whereIn('status', ['queued','processing','retrying'])
            ->update(['status' => 'failed', 'message' => 'Gemini could not create the image. The local 2D placement preview is shown instead.']);
    }
}
