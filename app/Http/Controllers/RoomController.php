<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoomStoreRequest;
use App\Jobs\AnalyzeRoomImage;
use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use App\Services\AiProvider;
use App\Services\GeminiService;
use App\Services\PrivacyAudit;
use App\Services\RoomPhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        return redirect()->to(route('preferences.edit').'#room-preview');
    }

    public function store(RoomStoreRequest $request, GeminiService $gemini, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        $data = $request->validated();
        $preference = $request->user()->preference()->first();
        if (! $preference || blank($preference->furniture_type)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Save your furniture preferences before uploading a room image.'], 422);
            }
            return redirect()->to(route('preferences.edit').'#preference-form')->withErrors(['workflow' => 'Save your furniture preferences before uploading a room image.']);
        }

        $path = null;
        $consented = $request->boolean('gemini_consent');
        try {
            [$room, $cachedAnalysis] = DB::transaction(function () use ($request, $photos, $data, $preference, $gemini, &$path) {
                $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $existing = $request->user()->roomImages()->limit(config('privacy.room_photo_max_count') + 1)->get(['image_path']);
                if ($existing->count() >= config('privacy.room_photo_max_count')) {
                    throw ValidationException::withMessages(['room_image' => 'Your saved photo limit is reached. Delete an unused photo before uploading another.']);
                }
                $path = $photos->storeUpload($request->file('room_image'), $request->user()->id);
                $photoMetadata = $photos->metadata($path);
                $bytes = Storage::disk('local')->size($path);
                foreach ($existing as $saved) {
                    if ($photos->exists($saved->image_path)) {
                        $bytes += $photos->size($saved->image_path);
                    }
                }
                if ($bytes > config('privacy.room_photo_max_bytes')) {
                    throw ValidationException::withMessages(['room_image' => 'Your saved photo storage limit is reached. Delete an unused photo before uploading another.']);
                }
                if (max($photoMetadata['width'], $photoMetadata['height']) < 600 || max($photoMetadata['width'], $photoMetadata['height']) > 1600) {
                    throw ValidationException::withMessages(['room_image' => 'The prepared room photo must be between 600 and 1600 pixels on its longest side. Select the photo again.']);
                }
                $consented = $request->boolean('gemini_consent');
                $cachedQuery = RoomImage::where('user_id', $request->user()->id)
                    ->where('image_sha256', $photoMetadata['sha256']);
                if ($consented) {
                    $cachedQuery->where('analysis_status', 'completed')->whereJsonContains('analysis->gemini_used', true);
                    $cachedQuery->where(fn ($query) => $query->where('gemini_consent_version', AiProvider::consentVersion())->orWhereNull('gemini_consent_version'));
                } else {
                    $cachedQuery->whereIn('analysis_status', ['completed', 'fallback']);
                }
                $cachedAnalysis = $cachedQuery->latest('analyzed_at')->first();
                if ($cachedAnalysis && data_get($cachedAnalysis->analysis, 'dimension_confidence') === 'customer-provided') {
                    $cachedAnalysis = null;
                }
                $width = isset($data['room_width_m']) ? (float) $data['room_width_m'] : null;
                $depth = isset($data['room_depth_m']) ? (float) $data['room_depth_m'] : null;
                if ($width !== null && $depth !== null) {
                    $preference->update(['room_width_m' => $width, 'room_depth_m' => $depth]);
                }
                $room = RoomImage::create([
                    'user_id' => $request->user()->id,
                    'image_path' => $path,
                    'image_sha256' => $photoMetadata['sha256'],
                    'image_width_px' => $photoMetadata['width'],
                    'image_height_px' => $photoMetadata['height'],
                    'room_width_m' => null,
                    'room_depth_m' => null,
                    'analysis' => $gemini->roomFallback(null, null, $preference->furniture_type),
                    'analysis_status' => 'queued',
                    'analysis_requested_at' => now(),
                    'gemini_consent_at' => $consented ? now() : null,
                    'gemini_consent_version' => $consented ? AiProvider::consentVersion() : null,
                ]);

                return [$room, $cachedAnalysis];
            });
        } catch (\Throwable $failure) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $failure;
        }
        if ($consented) {
            $audit->record('room_photo_gemini_consent_granted', 'room_image', $room->id, ['consent_version' => AiProvider::consentVersion()]);
        }
        if ($cachedAnalysis) {
            $room->update([
                'room_width_m' => data_get($cachedAnalysis->analysis, 'estimated_width_m'),
                'room_depth_m' => data_get($cachedAnalysis->analysis, 'estimated_depth_m'),
                'analysis' => Arr::except($cachedAnalysis->analysis ?? [], ['generated_previews', 'generated_preview_attempts']),
                'analysis_status' => $cachedAnalysis->analysis_status,
                'analysis_error' => $cachedAnalysis->analysis_error,
                'analyzed_at' => now(),
            ]);
        } elseif (! $consented || ! $gemini->isConfigured()) {
            $room->update([
                'analysis_status' => 'fallback',
                'analysis_error' => $consented
                    ? 'AI is not configured; safe local planning guidance is being used.'
                    : 'AI image processing was not authorized; local planning guidance is being used.',
                'analyzed_at' => now(),
            ]);
        } else {
            AnalyzeRoomImage::dispatch($room->id, 'all catalog furniture');
        }
        $room->refresh();
        if ($request->expectsJson()) {
            return response()->json([
                'room_id' => $room->id,
                'html' => view('preferences._room-option', ['room' => $room, 'activeRoomId' => $room->id])->render(),
            ], 201);
        }
        $message = in_array($room->analysis_status, ['completed', 'fallback'], true)
            ? 'Your room is ready and selected for recommendations.'
            : 'Your room was uploaded. FurniCraft is analyzing it in the background.';

        if (($data['return_to'] ?? null) === 'recommendations') {
            return redirect()->to(route('preferences.edit', ['room' => $room->id]).'#room-preview');
        }

        return redirect()->route('room.visualize', $room)->with('success', $message);
    }

    public function destroy(Request $request, RoomImage $roomImage, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        $roomId = $roomImage->id;
        DB::transaction(function () use ($roomImage, $photos) {
            $roomImage = RoomImage::whereKey($roomImage->id)->lockForUpdate()->firstOrFail();
            $photos->deleteRoomFiles($roomImage);
            $roomImage->delete();
        });
        $audit->record('room_photo_deleted', 'room_image', $roomId);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'The room photo and generated previews were deleted.', 'room_id' => $roomId]);
        }

        return redirect()->route('preferences.edit')->with('success', 'The room photo and generated previews were deleted.');
    }

    public function revokeAiConsent(Request $request, RoomImage $roomImage, RoomPhotoService $photos, PrivacyAudit $audit, GeminiService $gemini)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        DB::transaction(function () use ($roomImage, $photos, $gemini) {
            $roomImage = RoomImage::whereKey($roomImage->id)->lockForUpdate()->firstOrFail();
            $photos->deleteGeneratedPreviews($roomImage);
            RoomPreviewGeneration::where('room_image_id', $roomImage->id)->whereIn('status', ['queued', 'processing', 'retrying'])
                ->update(['status' => 'cancelled', 'message' => 'AI processing is disabled for this photo.']);
            $analysis = $gemini->roomFallback(null, null, 'all catalog furniture');
            $roomImage->update([
                'gemini_consent_at' => null,
                'gemini_consent_version' => null,
                'room_width_m' => null,
                'room_depth_m' => null,
                'analysis' => $analysis,
                'analysis_status' => 'fallback',
                'analysis_error' => 'AI processing disabled by you; local planning guidance is being used.',
                'analyzed_at' => now(),
            ]);
        });
        $audit->record('room_photo_gemini_consent_revoked', 'room_image', $roomImage->id);

        return back()->with('success', 'AI processing is disabled for this photo. Future AI requests are stopped and generated previews were deleted.');
    }

    public function reanalyze(Request $request, RoomImage $roomImage)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        RoomImage::recoverStalledForUser($request->user()->id);
        $roomImage->refresh();
        abort_if(in_array($roomImage->analysis_status, ['queued', 'processing', 'retrying'], true), 422, 'Room analysis is already in progress.');
        abort_if($roomImage->analysis_attempts > 0 && $roomImage->analyzed_at && $roomImage->analyzed_at->gt(now()->subMinute()),
            429, 'Please wait one minute before purchasing another room analysis.');

        if (! AiProvider::hasPhotoConsent($roomImage)) {
            $message = 'AI analysis is off for this photo or the current provider. Upload the photo again and choose AI consent; the local estimate remains available.';

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'status' => $roomImage->analysis_status], 422)
                : back()->with('warning', $message);
        }

        $roomImage = DB::transaction(function () use ($roomImage) {
            $current = RoomImage::whereKey($roomImage->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($current->analysis_status, ['queued', 'processing', 'retrying'], true), 422, 'Room analysis is already in progress.');
            abort_if($current->analysis_attempts > 0 && $current->analyzed_at?->gt(now()->subMinute()), 429, 'Please wait one minute before purchasing another room analysis.');
            abort_unless(AiProvider::hasPhotoConsent($current), 422, 'Photo consent is required.');
            $current->update(['analysis_status' => 'queued', 'analysis_error' => null, 'analysis_requested_at' => now()]);
            return $current;
        });
        AnalyzeRoomImage::dispatch($roomImage->id, 'all catalog furniture', true);
        $roomImage->refresh();

        $message = match ($roomImage->analysis_status) {
            'completed' => 'Room analysis completed successfully.',
            'fallback' => $roomImage->analysis_error ?: 'Local room guidance is ready.',
            default => 'Room re-analysis has been queued.',
        };

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'status' => $roomImage->analysis_status])
            : back()->with('success', $message);
    }

    public function analysisStatus(Request $request, RoomImage $roomImage, GeminiService $gemini)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        // Status GETs describe effective state without writing, dispatching or purchasing work.
        if (in_array($roomImage->analysis_status, ['queued', 'processing', 'retrying'], true)
            && ($roomImage->analysis_requested_at ?? $roomImage->updated_at)?->lt(now()->subMinutes(15))) {
            $roomImage->analysis_status = 'failed';
            $roomImage->analysis_error = 'Room analysis timed out. Re-analyze this saved photo to try again.';
        }
        if ($roomImage->analysis_status === 'completed' && data_get($roomImage->analysis, 'vision_geometry.image_quality.usable') === false) {
            $roomImage->analysis_status = 'fallback';
            $analysis = $roomImage->analysis;
            $analysis['gemini_used'] = false;
            $roomImage->analysis = $analysis;
        }
        if ($roomImage->analysis_status === 'queued' && (! AiProvider::hasPhotoConsent($roomImage) || ! $gemini->isConfigured())) {
            $roomImage->fill([
                'analysis_status' => 'fallback', 'analysis_error' => $roomImage->gemini_consent_at
                    ? 'AI is not configured; safe local planning guidance is being used.'
                    : 'AI image processing was not authorized; local planning guidance is being used.',
                'analyzed_at' => now(),
            ]);
        }

        $ready = in_array($roomImage->analysis_status, ['completed', 'fallback'], true);
        $product = $request->integer('product') ? \App\Models\FurnitureProduct::active()->find($request->integer('product')) : null;

        return response()->json([
            'status' => $roomImage->analysis_status,
            'attempts' => $roomImage->analysis_attempts,
            'message' => $roomImage->analysis_error,
            'ready' => $ready,
            'analyzed_at' => $roomImage->analyzed_at?->toIso8601String(),
            'analysis' => $ready ? $roomImage->analysis : null,
            'product_id' => $product?->id,
            'placement' => $ready && $product ? app(\App\Services\FurniturePlacementService::class)->forProduct($roomImage->analysis ?? [], $product) : null,
        ]);
    }
}
