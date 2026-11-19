# Room Preview v2 backend

The primary room preview automatically places the selected catalog image in 2D using the uploaded photo's detected floor or opening. It appears without waiting for a generated image or a visual-verification request. This follows the study's room-visualization scope in `funicraft-revised2.11.1.docx`: 2D furniture images, with no 3D, 360-degree rotation, or AR. Previously saved legacy room measurements remain preference/ranking information; customers do not need to enter measurements, and the visual placement uses photo geometry.

The v2 generated-image enhancement remains optional and disabled by default. It runs only after the customer explicitly presses the existing AI preview retry control. When v2 is disabled, that explicit control can request the existing visual check. A failed enhancement does not silently start another chain of AI requests. Photo analysis still requires explicit consent and a working queue worker.

If no safe placement is found, the preview displays the original room, explains the issue, and disables preview download. It does not force the product over a blocked area. Clear photos should show the floor and nearby walls; door previews require the full doorway frame. The user is never required to drag furniture or supply room dimensions.

## Request limits

| Customer action | Maximum submitted Gemini requests |
| --- | --- |
| Open, refresh, switch furniture, render or download the normal preview; poll status | 0 |
| Valid room-analysis or preview cache hit | 0 |
| Consented upload or explicit room re-analysis with a cache miss | 1 analysis |
| Explicit legacy visual check with a cache miss | 1 verification |
| Explicit enabled v2 enhancement with usable local input | 1 generation, then at most 1 verification |
| Disabled v2, blocked placement, missing/transparent image, or unavailable local renderer | 0 enhancement calls |

The outbound `GeminiClient` reserves an action/operation key in `ai_action_attempts` in the same transaction as the aggregate spending reservation, before sending. The key survives failed responses, timeouts, worker death and cache loss. Room operations do not resubmit or switch models automatically. Stored consent is rechecked at that boundary for application photo actions. Existing aggregate/per-user limits, beta deadline and circuit breaker still apply. An attempted request consumes its reservation even if the provider fails. Local rejection of a generated candidate happens before paid verification; an unchanged room or a shadow alone cannot pass that local gate.

Analysis jobs carry their dispatch snapshot; v2 jobs carry a distinct attempt token. Duplicate deliveries cannot claim processing twice. A fresh v2 retry retains lifetime attempts and request metrics, uses a new token and observes a one-minute cooldown. The approved cumulative `max_provider_calls` cap still applies to the room/product preview record; the remaining allowance comes from durable reservations as well as metrics, with at most two requests per new action. Clearing metrics/cache or omitting `retry` cannot reset accounting. Status endpoints do not dispatch work or persist recovery state.

## Enable for a staged rollout

Set these values in the local or deployment environment, then clear cached config:

```dotenv
ROOM_PREVIEW_V2_ENABLED=true
ROOM_PREVIEW_V2_ROLLOUT_PERCENT=5
ROOM_PREVIEW_PIPELINE_VERSION=v2
ROOM_IMAGE_RETENTION_DAYS=30
```

Apply the additive migrations before starting the new code, including the action-reservation table and generation attempt token. Clear cached configuration with `php artisan config:clear`, restart workers and use `php artisan queue:work --queue=ai,default --tries=1 --timeout=540`. Drain or expire pre-deployment AI jobs rather than deliberately replaying them. Do not change an existing approved rollout or spending limit as part of a repair. To stop v2 set `ROOM_PREVIEW_V2_ENABLED=false` and clear config cache.

The configured queue visibility/retry interval must exceed the longest job timeout: v2 and compatibility generation jobs use 540 seconds and 600-second unique locks; room analysis uses a 240-second timeout and a 300-second per-room lock. Database, Redis and Beanstalk defaults are 600 seconds. Verify effective environment overrides and worker command-line settings; SQS visibility is configured externally. Durable reservations still prevent a submitted operation from being purchased twice after redelivery. Multi-worker production behavior must be checked in staging before deployment.

The authenticated POST endpoint accepts `furniture_id` and an optional `retry` boolean: `/room/{roomImage}/preview-v2`. `retry` requests a fresh attempt only when the cached result is a fallback. It returns HTTP 202 with a status URL, or the cached completed/fallback result. Poll the status URL; rendered results include a short-lived signed artifact URL. The request never accepts prompts, client image bytes, product measurements, or placement coordinates. Owner checks, active catalog state, complete product dimensions, and the 10-per-hour route limit are enforced server-side.

## Runtime requirements and behavior

The normal browser canvas preview does not require server GD. GD is required for v2 downscaling, image-quality sampling, deterministic PNG compositing and pixel comparison. JPEG EXIF orientation is normalized consistently for uploads, legacy server rendering and provider input, including mirrored orientations. Generated image metadata is stripped. Without GD, v2 reports a placement-only rough estimate and does not start paid enhancement. Verify GD and EXIF in the PHP runtime used by both web and queue workers before enabling enhancement.

AI processing requires explicit stored photo consent. Completed broad room analysis is reused across furniture choices from the uploaded room record and shared cache. The shared analysis cache is keyed by photo SHA-256 and pipeline version and protected by a lock. Artifact identity includes actual room bytes, saved analysis, analysis request snapshot, consent, catalog attributes, actual catalog image bytes, provider/model and placement version. A stale artifact is neither returned nor served after those inputs change.

The optional image generator receives server-calculated image coordinates and actual composite bounds. Its output must pass local pixel checks first, then the five-score verifier. A catalog furniture set counts as one product with all photographed pieces. Rejected output retains a clearly labeled local estimate. The approved provider setting remains unchanged at eight, while this path enforces a tighter ceiling of two and one attempt per phase. FurniCraft budget exhaustion and a Google quota response have separate failure codes. Cancelled or superseded jobs cannot save an artifact over a newer request.

Door fit corners are distinct from detection bboxes. Valid room-facing corners survive `candidate_zones`, top-level `openings`, and `vision_geometry.openings`; they must be ordered top-left, top-right, bottom-right, bottom-left, in percentages (older fractional quads are converted as a whole). Validation rejects crossing, degenerate, partial and out-of-range frames without reordering or clamping them into an invented fit. Window geometry never becomes a doorway. Door installation does not use floor-furniture collision rules. Other furniture still respects opening visibility, obstacles and walkways. A detected doorway without fit corners gets specific feedback and an explicit saved-photo re-analysis action.

Room analysis may make at most one additional, separately reserved Gemini request when usable placement zones are missing or a visible/requested doorway lacks fit corners. The retry receives the same room photo only under the existing stored-consent gate, and counts against the same usage and spend budgets. Invalid retry coordinates are discarded. Non-door furniture can still use a bounded automatic fit inside a validated floor polygon; without a validated zone or floor region the preview stays unplaced instead of using a furniture-type default.

The browser checks the product-only raster layer before enabling export. An alpha-empty image, placeholder, failed decode or failed draw leaves the original room visible and disables download. Recommendations initially reveals the placed item's location, including letterboxed portrait photos; touch and keyboard comparison controls remain available. Pending analysis updates both pages without replacing the selected product, and obsolete asynchronous work cannot replace the current canvas.

Server compositing applies validated opening quadrilaterals, 2D rotation/perspective/flip, and transparent-border cropping while retaining the source product's appearance. It never guesses that a white or opaque product region is background. Catalog photographs with opaque backgrounds therefore still need genuine cutouts. These transforms do not recover hidden product surfaces or guarantee physical dimensions from a single photograph. Verify actual measurements before purchase or installation.

Room images and generated artifacts are private. The normal retention cleanup removes room artifacts and stale shared analysis cache rows; the configured default is 90 days. API keys are sent in the Gemini authentication header, not in query strings. Never put customer photos or API keys in benchmark fixtures or logs.

## Benchmark

Regression coverage includes server placement propagation, shared analysis/cooldown, cancelled jobs, safe floor search, opening perspective, product aspect ratio, and artifact bounds. JavaScript tests cover immediate rendering, blocked floors, stale download prevention, and optional enhancement failures. The browser rasterizes decoded catalog images to at most 1600 pixels on their longest side before alpha cropping, preventing SVG crop operations from silently drawing an invisible product. Browser checks assert visible furniture-body pixels, excluding contact shadows, in an isolated database with a table illustration and synthetic room; they do not modify the shop catalog or send photos to a live provider.

`php artisan roompreview:benchmark` expects 8 to 10 non-sensitive room fixtures matching `tests/Fixtures/room-*.png`. You can specify a different fixture directory with `--path=`. The current command is intentionally offline: it measures preprocessing time, output size, duplicate hashes, and failures. It does not make provider requests, estimate live Gemini cost, or claim scale, fidelity, or verification scores without annotations. Those figures must be collected from a properly labeled set before publishing performance claims.

## Files involved in the placement repair

- `app/Services/FurniturePlacementService.php`, `RoomPreviewRasterService.php`, `RoomPreviewV2Service.php`, `GeminiRoomVisionService.php`, `GeminiService.php`.
- `app/Jobs/AnalyzeRoomImage.php`, `GenerateRoomPreviewV2.php`; `app/Http/Controllers/RoomPreviewV2Controller.php`; `config/roompreview.php`.
- `public/js/room-preview-engine.js`; `public/css/furnicraft.css` (scoped preview containment); `resources/views/preferences/edit.blade.php`, `room/visualize.blade.php`, `recommendations/show.blade.php`.
- `tests/Feature/AutomaticPlacementSafetyTest.php`, `AutomaticRoomFittingPipelineTest.php`, `RoomPreviewRasterPlacementTest.php`, `RoomPreviewWorkflowPresentationTest.php`, `PreviewRetryTest.php`, `PreviewVerificationStatusTest.php`.
- `tests/room-preview-engine.test.js`; `tests/Browser/automatic-room-fit.spec.js`, `seed-mobile.php`; this document.

## Rollback

Disable v2 and clear config cache to stop enhancement while retaining the normal browser preview. Keep action reservations, attempt tokens and spending records intact during rollback; dropping them can erase protection against duplicate purchases. Do not run a blanket migration rollback against retained AI jobs or artifacts.
