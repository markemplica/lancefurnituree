<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomImage extends Model
{
    protected $fillable = ['user_id', 'image_path', 'image_sha256', 'image_width_px', 'image_height_px', 'room_width_m', 'room_depth_m', 'analysis', 'analysis_status', 'analysis_attempts', 'analysis_error', 'analysis_requested_at', 'gemini_consent_at', 'gemini_consent_version', 'analyzed_at'];

    protected function casts(): array
    {
        return ['analysis' => 'array', 'image_width_px' => 'integer', 'image_height_px' => 'integer', 'analysis_attempts' => 'integer', 'analysis_requested_at' => 'datetime', 'gemini_consent_at' => 'datetime', 'analyzed_at' => 'datetime', 'room_width_m' => 'decimal:2', 'room_depth_m' => 'decimal:2'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function recoverStalledForUser(int $userId): void
    {
        static::where('user_id', $userId)->where('analysis_status', 'completed')
            ->where('analysis->vision_geometry->image_quality->usable', false)
            ->update(['analysis_status' => 'fallback', 'analysis->gemini_used' => false,
                'analysis_error' => 'This photo is not clear enough for AI placement. Upload a clearer photo; local guidance remains available.']);
        static::where('user_id', $userId)->whereIn('analysis_status', ['queued', 'processing', 'retrying'])
            ->where(function ($query) {
                $query->where('analysis_requested_at', '<', now()->subMinutes(15))
                    ->orWhere(fn ($q) => $q->whereNull('analysis_requested_at')->where('updated_at', '<', now()->subMinutes(15)));
            })->update(['analysis_status' => 'failed', 'analysis_error' => 'Room analysis timed out. Local guidance remains available. Choose another saved photo or upload a clearer photo from Plan your room.']);
    }
}
