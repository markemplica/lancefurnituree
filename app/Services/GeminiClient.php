<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Legacy facade name retained for existing jobs; transport follows AiProvider exclusively.
class GeminiClient
{
    private int $providerRequests = 0;

    private ?int $requestCeiling = null;

    private ?int $actorId = null;

    public function __construct(private AiTelemetry $telemetry) {}

    public function setActor(?int $id): void
    {
        $this->actorId = $id;
    }

    public function isConfigured(): bool
    {
        return filled(AiProvider::key()) && config('services.gemini.enabled')
            && AiUsageBudget::accessWindowOpen()
            && (int) config('services.gemini.daily_request_limit') > 0
            && (int) config('services.gemini.monthly_request_limit') > 0
            && (int) config('services.gemini.daily_budget_micro_usd') > 0
            && (int) config('services.gemini.monthly_budget_micro_usd') > 0;
    }

    public function limitProviderRequests(int $n): void
    {
        $this->requestCeiling = $this->providerRequests + max(0, $n);
    }

    public function clearProviderRequestLimit(): void
    {
        $this->requestCeiling = null;
    }

    public function withRequestLimit(int $limit, callable $operation): mixed
    {
        $previous = $this->requestCeiling;
        $ceiling = $this->providerRequests + max(0, $limit);
        $this->requestCeiling = $previous === null ? $ceiling : min($previous, $ceiling);
        try {
            return $operation();
        } finally {
            $this->requestCeiling = $previous;
        }
    }

    public function providerCallCount(): int
    {
        return $this->providerRequests;
    }

    private function countProviderRequest(bool $image, string $operation): void
    {
        if ($this->requestCeiling !== null && $this->providerRequests >= $this->requestCeiling) {
            throw new AiRequestBudgetExceeded;
        }
        // The unique durable claim survives worker death, lock expiry and redelivery.
        // Never release it after a submitted request, even on timeout or malformed output.
        $action = Context::getHidden('ai_action_key');
        DB::transaction(function () use ($action, $operation, $image) {
            // Claim first: SQLite also acquires its write reservation before any reads.
            if ($action && ! DB::table('ai_action_attempts')->insertOrIgnore([
                'attempt_key' => hash('sha256', $action.'|'.$operation),
                'action_key' => $action,
                'operation' => $operation, 'created_at' => now(),
            ])) throw new AiRequestBudgetExceeded;
            if (is_string($action) && preg_match('/^preview-v2:(\d+):(.+)$/D', $action, $parts)) {
                $transfer = Context::getHidden('ai_photo_transfer');
                $room = \App\Models\RoomImage::whereKey($transfer['room_id'] ?? 0)->lockForUpdate()->first();
                $generation = \App\Models\RoomPreviewGeneration::whereKey((int) $parts[1])->lockForUpdate()->first();
                if (! $room || ! $generation || (int) $generation->room_image_id !== (int) $room->id
                    || $parts[2] !== ($generation->attempt_token ?? $generation->cache_key)) throw new \RuntimeException('preview_inputs_changed');
                $product = \App\Models\FurnitureProduct::find($generation->furniture_product_id);
                if (! $product || ! hash_equals($generation->cache_key, RoomPreviewIdentity::key($room, $product))) throw new \RuntimeException('preview_inputs_changed');
                if (! in_array($operation, ['furnished_preview', 'room_preview_verification'], true)
                    || DB::table('ai_action_attempts')->where('action_key', 'like', 'preview-v2:'.$generation->id.':%')->count() > max(0, (int) config('roompreview.max_provider_calls', 8))) {
                    throw new AiRequestBudgetExceeded;
                }
            }
            app(AiUsageBudget::class)->reserve($image, auth()->id() ?? $this->actorId ?? Context::getHidden('ai_user_id'), $operation);
        });
        $this->providerRequests++;
    }

    public function generate(string $prompt, string $fallback, string $operation, int $timeout = 30, int $retryAttempts = 1, int $modelLimit = 1, ?string $systemInstruction = null): array
    {
        if (! $this->isConfigured()) {
            return ['text' => $fallback, 'used' => false, 'source' => 'local-fallback'];
        }
        try {
            return ['text' => $this->request([['text' => $prompt]], operation: $operation, timeout: $timeout, retryAttempts: $retryAttempts, modelLimit: $modelLimit, systemInstruction: $systemInstruction), 'used' => true, 'source' => AiProvider::name()];
        } catch (Throwable $e) {
            $this->telemetry->record('text', $operation, 'fallback', fallbackUsed: true, failureCode: $this->telemetry->failureCode($e));
            try {
                Log::warning('AI request failed', ['provider' => AiProvider::name()] + $this->errorContext($e));
            } catch (Throwable) {
            }

            return ['text' => $fallback, 'used' => false, 'source' => 'local-fallback'];
        }
    }

    public function generateText(array $parts, bool $json = false, ?array $schema = null, string $operation = 'text_generation', int $timeout = 30, int $retryAttempts = 1, int $modelLimit = 1, ?string $systemInstruction = null): string
    {
        return $this->request($parts, $json, $schema, $operation, $timeout, $retryAttempts, $modelLimit, $systemInstruction);
    }

    private function request(array $parts, bool $json = false, ?array $schema = null, string $operation = 'text_generation', int $timeout = 30, int $retryAttempts = 1, int $modelLimit = 1, ?string $systemInstruction = null): string
    {
        if (! $this->isConfigured()) {
            throw new AiAggregateBudgetExceeded;
        }
        $chat = in_array($operation, ['chat_response', 'recommendation_explanation'], true);
        $singleCall = in_array($operation, ['room_analysis', 'room_analysis_geometry_retry', 'room_preview_verification', 'chat_response', 'recommendation_explanation'], true);
        if ($singleCall) {
            $retryAttempts = 0;
            $modelLimit = 1;
        }
        $models = array_values(array_unique(array_filter($chat ? [AiProvider::model(true)] : [AiProvider::model(), AiProvider::name() === 'gemini' ? config('services.gemini.fallback_model') : null])));
        $remaining = $singleCall ? 1 : max(1, min(3, (int) config('services.gemini.max_calls_per_operation', 3)));
        $last = null;
        foreach (array_slice($models, 0, max(1, min(2, $modelLimit))) as $model) {
            try {
                $payload = ['contents' => [['parts' => $parts]], 'generationConfig' => [
                    'temperature' => $json ? 0 : .2, 'candidateCount' => 1,
                    'maxOutputTokens' => min(4096, max(128, (int) config('services.gemini.'.($chat ? 'chat_output_tokens' : 'analysis_output_tokens')))),
                ]];
                if ($json) {
                    $payload['generationConfig']['responseMimeType'] = 'application/json';
                }
                if ($json && $schema) {
                    if (in_array($operation, ['room_analysis', 'room_analysis_geometry_retry', 'room_preview_verification'], true)) {
                        // One JSON-mode call avoids a rejected complex schema followed by another paid call.
                        $payload['contents'][0]['parts'][] = ['text' => 'Return JSON only matching this schema. Coordinates must follow the visible photo; omit uncertain door fit corners. '.json_encode($schema)];
                    } else {
                        $payload['generationConfig']['responseJsonSchema'] = $schema;
                    }
                }
                if ($systemInstruction !== null) {
                    $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
                }
                $response = $this->send($model, $payload, $operation, $timeout, false, $remaining, $retryAttempts);
                $error = strtolower((string) data_get($response->json(), 'error.message', ''));
                if (! $singleCall && $response->status() === 400 && $json && $schema && (str_contains($error, 'schema') || str_contains($error, 'structured'))) {
                    unset($payload['generationConfig']['responseJsonSchema']);
                    $payload['contents'][0]['parts'][] = ['text' => 'Return JSON only matching this schema: '.json_encode($schema)];
                    $response = $this->send($model, $payload, $operation, $timeout, false, $remaining, 0);
                }
                $response->throw();
                $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
                if ($text === '') {
                    throw new \RuntimeException('Empty AI response');
                }

                return $text;
            } catch (Throwable $e) {
                $last = $e;
                $status = $this->telemetry->statusCode($e);
                if ($e instanceof AiAggregateBudgetExceeded || $e instanceof AiRequestBudgetExceeded || in_array($status, [400, 401, 403, 429], true)) {
                    throw $e;
                }
                if ($remaining <= 0) {
                    break;
                }
            }
        }
        if ($last && ($this->telemetry->statusCode($last) >= 500 || $last instanceof ConnectionException)) {
            app(AiUsageBudget::class)->tripCircuit(60, 'unavailable');
        }
        throw $last ?? new \RuntimeException('No approved text model configured');
    }

    public function generateImage(array $parts, int $timeout = 120, int $retryAttempts = 0, int $modelLimit = 1): array
    {
        if (AiProvider::name() !== 'gemini' || ! $this->isConfigured() || ! config('services.gemini.image_enabled')) {
            throw new AiAggregateBudgetExceeded;
        }
        $remaining = 1;
        $response = $this->send((string) config('services.gemini.image_model'), [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['responseModalities' => ['IMAGE'], 'candidateCount' => 1, 'maxOutputTokens' => 4096, 'imageConfig' => ['imageSize' => '1K']],
        ], 'furnished_preview', $timeout, true, $remaining, 0);
        $response->throw();
        $part = collect(data_get($response->json(), 'candidates.0.content.parts', []))->first(fn ($p) => filled(data_get($p, 'inlineData.data')) || filled(data_get($p, 'inline_data.data')));
        $encoded = data_get($part, 'inlineData.data') ?: data_get($part, 'inline_data.data');
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! $bytes || strlen($bytes) > 8_000_000) {
            throw new \RuntimeException('Invalid generated image');
        }

        return ['bytes' => $bytes, 'mime_type' => data_get($part, 'inlineData.mimeType') ?: data_get($part, 'inline_data.mime_type') ?: 'image/png', 'used' => true];
    }

    private function send(string $model, array $payload, string $operation, int $timeout, bool $image, int &$remaining, int $retryAttempts): Response
    {
        if (! in_array($model, config('services.'.AiProvider::name().'.'.($image ? 'allowed_image_models' : 'allowed_text_models'), []), true)) {
            throw new \RuntimeException('Model is not approved by the spending policy');
        }
        $this->validatePayload($payload);
        $headers = ['x-goog-api-key' => AiProvider::key()];
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        $tries = $image ? 1 : ($retryAttempts > 0 ? 2 : 1);
        for ($attempt = 0; $attempt < $tries; $attempt++) {
            if ($remaining-- <= 0) {
                throw new AiRequestBudgetExceeded;
            }
            $started = hrtime(true);
            try {
                $response = Http::withHeaders($headers)
                    ->connectTimeout(5)->timeout(max(1, min($image ? 120 : 30, $timeout)))
                    ->withOptions(['allow_redirects' => false])
                    ->beforeSending(function () use ($image, $operation, $payload) {
                        $transfer = Context::getHidden('ai_photo_transfer');
                        $hasPhoto = collect($payload['contents'][0]['parts'] ?? [])->contains(fn ($part) => isset($part['inline_data']) || isset($part['inlineData']));
                        if ($hasPhoto && Context::getHidden('ai_action_key') && ! is_array($transfer)) throw new \RuntimeException('photo_consent_required');
                        if ($hasPhoto && is_array($transfer)) {
                            $room = \App\Models\RoomImage::find($transfer['room_id'] ?? 0);
                            if (! $room || ! AiProvider::hasPhotoConsent($room) || $room->user_id !== ($transfer['user_id'] ?? null)) throw new \RuntimeException('photo_consent_required');
                        }
                        $this->countProviderRequest($image, $operation);
                        if ($hasPhoto && is_array($transfer)) {
                            app(PrivacyAudit::class)->record('room_photo_sent_to_gemini', 'room_image', $transfer['room_id'], ['purpose' => $transfer['purpose']], $transfer['user_id']);
                        }
                    })
                    ->post($endpoint, $payload);
                $usage = (array) data_get($response->json(), 'usageMetadata', []);
                $reason = $this->safeProviderReason($response);
                $this->telemetry->record($image ? 'image' : 'text', $operation, $response->successful() ? 'success' : 'failure', $model, $response->status(), (int) ((hrtime(true) - $started) / 1_000_000), failureCode: $reason, usage: $usage);
                if (in_array($response->status(), [401, 403, 429], true)) {
                    app(AiUsageBudget::class)->tripCircuit($response->status() === 429 ? max(60, (int) $response->header('Retry-After')) : 300, $response->status() === 429 ? 'quota' : 'credentials');
                    $response->throw();
                }
                if ($response->status() >= 300 && $response->status() < 400) {
                    throw new \RuntimeException('Provider redirect rejected');
                }
                if (! in_array($response->status(), [408, 500, 502, 503, 504], true) || $attempt + 1 >= $tries || $remaining <= 0) {
                    return $response;
                }
            } catch (ConnectionException $e) {
                $this->telemetry->record($image ? 'image' : 'text', $operation, 'failure', $model, latencyMs: (int) ((hrtime(true) - $started) / 1_000_000), failureCode: 'connection_error');
                app(AiUsageBudget::class)->tripCircuit(60, 'connection');
                throw $e;
            }
            usleep(random_int(100000, 250000));
        }
        throw new AiRequestBudgetExceeded;
    }

    private function validatePayload(array $payload): void
    {
        $textBytes = strlen(json_encode($payload['systemInstruction'] ?? [])) + strlen(json_encode($payload['generationConfig']['responseJsonSchema'] ?? []));
        $images = 0;
        foreach ($payload['contents'][0]['parts'] ?? [] as $part) {
            $textBytes += strlen((string) ($part['text'] ?? ''));
            $inline = $part['inline_data'] ?? $part['inlineData'] ?? null;
            if ($inline) {
                $images++;
                $encoded = (string) ($inline['data'] ?? '');
                if (! in_array($inline['mime_type'] ?? $inline['mimeType'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
                    || strlen($encoded) > (int) config('services.gemini.max_inline_bytes') * 4 / 3 + 4) {
                    throw new \RuntimeException('AI image input exceeds policy');
                }
                $bytes = base64_decode($encoded, true);
                $info = $bytes === false ? false : @getimagesizefromstring($bytes);
                if (! $info || $info[0] * $info[1] > 4_000_000 || max($info[0], $info[1]) > 2048) {
                    throw new \RuntimeException('AI image dimensions exceed policy');
                }
            } elseif (! array_key_exists('text', $part)) {
                throw new \RuntimeException('Unsupported AI input part');
            }
        }
        if ($images > 3 || $textBytes > (int) config('services.gemini.max_text_bytes')) {
            throw new \RuntimeException('AI prompt exceeds policy');
        }
    }

    public function errorContext(Throwable $exception): array
    {
        $context = ['exception' => $exception::class];
        if (property_exists($exception, 'response') && $exception->response) {
            $context['status'] = $exception->response->status();
            $context['provider_reason'] = $this->safeProviderReason($exception->response);
        }

        return $context;
    }

    private function safeProviderReason(Response $response): ?string
    {
        if ($response->successful()) return null;
        $values = [data_get($response->json(), 'error.status')];
        $details = data_get($response->json(), 'error.details', []);
        foreach (is_array($details) ? $details : [] as $detail) {
            if (is_array($detail)) $values[] = $detail['reason'] ?? null;
        }
        $values = array_filter($values, fn ($value) => is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{0,60}$/D', $value));
        return $values ? substr(implode(':', array_unique($values)), 0, 100) : 'http_'.$response->status();
    }
}
