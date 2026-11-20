<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatMessageRequest;
use App\Models\ChatMessage;
use App\Models\Recommendation;
use App\Services\GeminiService;
use App\Services\ChatPreferenceFilter;
use App\Services\RecommendationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ChatController extends Controller
{
    private const DAILY_MESSAGE_LIMIT = 100;
    private const DAILY_MESSAGE_DECAY_SECONDS = 86400;

    public function store(ChatMessageRequest $request, Recommendation $recommendation, GeminiService $gemini, ChatPreferenceFilter $filters, RecommendationService $ranking)
    {
        abort_unless($recommendation->user_id === $request->user()->id, 403);
        $data = $request->validated();
        $user = $request->user();
        $rateLimitKey = 'chat-daily:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::DAILY_MESSAGE_LIMIT)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => "You have reached today's chat limit. Try again tomorrow."], 429);
            }

            return back()->withErrors(['message' => "You have reached today's chat limit. Try again tomorrow."]);
        }
        RateLimiter::hit($rateLimitKey, self::DAILY_MESSAGE_DECAY_SECONDS);

        ChatMessage::create(['user_id' => $user->id, 'recommendation_id' => $recommendation->id, 'role' => 'user', 'message' => $data['message']]);
        $filter = $filters->parse($data['message'], $recommendation->preference);
        if (isset($filter['needs_clarification'])) {
            $answer = ['used' => false, 'source' => 'catalog-rule', 'text' => $filter['needs_clarification']];
        } elseif ($filter) {
            DB::transaction(function () use ($recommendation, $request, $filter, $ranking) {
                $preference = $recommendation->preference()->lockForUpdate()->firstOrFail();
                $preference->update($filter['updates']);
                $ranking->generate($request->user(), $preference, $recommendation->roomImage, $filter['updates']['furniture_type'] ?? $recommendation->furniture_type, $recommendation);
            });
            $count = $recommendation->items()->count();
            $answer = ['used' => false, 'source' => 'catalog-rule', 'text' => 'Updated your saved preferences and ranked matches: '.$filter['description'].'. '
                .($count ? $count.' matching products found.' : 'No products match all your current filters. Change your budget or say "any wood", "any style", or "any finish" to broaden your choices.')];
            if (! $count) {
                $suggestions = $ranking->closestSuggestions($recommendation->preference, $recommendation->furniture_type);
                $answer['text'] .= $suggestions->isEmpty()
                    ? ' There are no active alternatives for your selected furniture type.'
                    : ' Closest alternatives (not exact matches): '.$suggestions->map(fn ($row) => $row['product']->name.' — PHP '.number_format($row['product']->price, 2).' ('.implode('; ', $row['differences']).')')->implode('. ').'. Your filters have not been relaxed.';
            }
        } else {
            $answer = $gemini->chat($recommendation->load('items.product'), $data['message']);
        }
        $source = $answer['source'] ?? ($answer['used'] ? \App\Services\AiProvider::name() : 'local-fallback');
        ChatMessage::create(['user_id' => $request->user()->id, 'recommendation_id' => $recommendation->id, 'role' => 'assistant', 'message' => $answer['text'], 'gemini_used' => $answer['used'], 'ai_source' => $source]);

        $notice = match ($source) {
            'local-fallback' => 'AI is temporarily unavailable. FurniCraft replied using local catalog guidance.',
            'catalog-rule' => 'FurniCraft replied using current catalog listings. Physical availability still needs confirmation with Lance Furniture Shop.',
            default => 'FurniCraft replied to your question.',
        };

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $answer['text'],
                'source' => $source,
                'notice' => $notice,
                'preferences_updated' => (bool) $filter && ! isset($filter['needs_clarification']),
            ]);
        }

        if (isset($filter['needs_clarification'])) {
            return back()->with('success', $answer['text']);
        }

        return redirect()->route('recommendations.show', $recommendation)->with('success', $filter ? 'Preferences, ranked matches, and closest suggestions updated.' : $notice);
    }
}
