<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyRoomPreviewRequest;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use App\Services\FurniturePlacementService;
use App\Services\GeminiService;
use App\Services\PrivacyAudit;
use App\Services\RoomPhotoService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoomVisualizationController extends Controller
{
    public function visualize(Request $request, RoomImage $roomImage, GeminiService $gemini, FurniturePlacementService $placementService, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        abort_unless($roomImage->user_id === $request->user()->id || $request->user()->isAdmin(), 403);

        $recommendation = null;

        if ($request->filled('recommendation')) {
            $recommendation = Recommendation::with('items.product')
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->integer('recommendation'));

            abort_unless($recommendation->room_image_id === $roomImage->id, 403);
        }

        $products = FurnitureProduct::where('status', 'active')->orderBy('name')->get();
        $firstRecommendedProduct = $recommendation?->items->first()?->product;
        $selectedProduct = $request->filled('product')
            ? $products->firstWhere('id', $request->integer('product'))
            : ($firstRecommendedProduct ?? $products->first());

        abort_if($request->filled('product') && ! $selectedProduct, 404);

        $analysis = $roomImage->analysis ?? [];

        // Rendering a GET page must never queue billable analysis. Use the explicit POST action.

        $selectedPlacement = $selectedProduct
            ? $placementService->forProduct(
                $roomImage->analysis ?? [],
                $selectedProduct,
            )
            : null;

        return view('room.visualize', compact('roomImage', 'selectedProduct', 'recommendation', 'selectedPlacement'));
    }

    public function verifyPreview(VerifyRoomPreviewRequest $request, RoomImage $roomImage, FurnitureProduct $product, GeminiService $gemini)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        abort_unless(\App\Services\AiProvider::hasPhotoConsent($roomImage) && $gemini->isConfigured(), 403, 'AI preview checking requires consent for the current provider.');
        abort_unless($product->status === 'active', 404);
        abort_unless($product->width_cm && $product->depth_cm && $product->height_cm, 422, 'Dimensions not available for this item.');

        $data = $request->validated();

        $previewBase64 = preg_replace('/^data:[^,]*;base64,/i', '', $data['preview'], 1);
        $previewBytes = base64_decode($previewBase64, true);

        if ($previewBytes === false) {
            throw ValidationException::withMessages([
                'preview' => 'The preview image must contain valid base64 data.',
            ]);
        }

        if (strlen($previewBytes) > 2_000_000) {
            throw ValidationException::withMessages([
                'preview' => 'The preview image must be no larger than 2 MB.',
            ]);
        }

        $imageInfo = @getimagesizefromstring($previewBytes);
        if ($imageInfo === false || ! in_array($imageInfo['mime'] ?? null, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw ValidationException::withMessages([
                'preview' => 'The preview must be a PNG, JPEG, or WebP image.',
            ]);
        }

        $fallback = ['verdict' => 'good', 'issues' => [], 'corrections' => ['dx' => 0, 'dy' => 0, 'scale_factor' => 1, 'flip' => null], 'realism_score' => .35, 'valid' => false];
        $cacheKey = 'ai-fit-check:'.hash('sha256', \App\Services\AiProvider::name().'|'.\App\Services\AiProvider::model().'|'.$roomImage->user_id.'|'.$roomImage->id.'|'.$roomImage->updated_at?->toISOString().'|'.$product->id.'|'.$product->updated_at?->toISOString().'|'.hash('sha256', $previewBytes).'|'.json_encode($data['placement']));
        try {
            $result = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if (! $result) {
                $lock = \Illuminate\Support\Facades\Cache::lock($cacheKey.':lock', 90);
                if (! $lock->get()) return response()->json($fallback);
                try {
                    $previousAction = \Illuminate\Support\Facades\Context::getHidden('ai_action_key');
                    \Illuminate\Support\Facades\Context::addHidden('ai_action_key', $cacheKey);
                    \Illuminate\Support\Facades\Context::addHidden('ai_photo_transfer', ['room_id' => $roomImage->id, 'purpose' => 'room_preview_verification', 'user_id' => $roomImage->user_id]);
                    $result = \Illuminate\Support\Facades\Cache::get($cacheKey) ?? $gemini->verifyRoomPlacement($roomImage->image_path, $previewBase64, $data['placement']);
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $result, ($result['valid'] ?? false) ? 600 : 30);
                } finally {
                    $previousAction === null ? \Illuminate\Support\Facades\Context::forgetHidden('ai_action_key') : \Illuminate\Support\Facades\Context::addHidden('ai_action_key', $previousAction);
                    \Illuminate\Support\Facades\Context::forgetHidden('ai_photo_transfer');
                    $lock->release();
                }
            }
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Visual check safely fell back', ['exception' => $exception::class]);
            $result = $fallback;
        }

        $generation = RoomPreviewGeneration::firstOrNew([
            'room_image_id' => $roomImage->id,
            'furniture_product_id' => $product->id,
        ]);
        // The legacy verifier must not replace v2 artifacts, cooldowns or attempt accounting.
        if ($generation->exists && filled($generation->cache_key)) return response()->json($result);
        $history = $generation->placement_json ?? ['iterations' => []];
        $history['iterations'][] = [
            'placement' => $data['placement'],
            'verifier' => $result,
            'checked_at' => now()->toIso8601String(),
        ];
        $history['iterations'] = array_slice($history['iterations'], -3);
        $generation->fill([
            'status' => 'completed',
            'attempts' => count($history['iterations']),
            'placement_json' => $history,
            'message' => $result['valid'] ? 'Automatic visual check completed.' : 'Safe local placement retained; AI visual check was unavailable.',
        ])->save();

        return response()->json($result);
    }

    public function previewStatus(Request $request, RoomImage $roomImage, int $product)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        abort_unless($roomImage->gemini_consent_at, 404);
        $generation = RoomPreviewGeneration::where('room_image_id', $roomImage->id)
            ->where('furniture_product_id', $product)->firstOrFail();
        $path = data_get($roomImage->analysis, 'generated_previews.'.$product.'.path');

        return response()->json([
            'status' => $generation->status,
            'message' => $generation->message,
            'preview_url' => $generation->status === 'completed' && $path
                ? route('room-images.preview', [$roomImage, $product])
                : null,
        ]);
    }
}
