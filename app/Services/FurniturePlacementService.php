<?php

namespace App\Services;

use App\Models\FurnitureProduct;

class FurniturePlacementService
{
    public function forProduct(array $analysis, FurnitureProduct $product, ?float $roomWidth = null, ?float $roomDepth = null): array
    {
        $type = strtolower($product->recommendationType());
        $preferredSurface = $this->preferredSurface($type);
        $measurement = $this->measurementAssessment($product, $roomWidth, $roomDepth, $type);
        $zones = collect($analysis['candidate_zones'] ?? [])->filter(fn ($zone) => is_array($zone));

        if (str_contains($type, 'door')) {
            $zones = $zones->concat($this->openingZones($analysis));
        }

        $ranked = $zones->filter(fn (array $zone) => $this->zoneIsCompatible($zone, $type, $preferredSurface))
            ->map(function (array $zone) use ($analysis, $type, $preferredSurface, $product, $roomWidth, $roomDepth) {
            $surface = strtolower((string) ($zone['surface'] ?? 'floor'));
            $suitableTypes = collect($zone['suitable_types'] ?? [])->map(fn ($value) => strtolower((string) $value));
            $confidence = $this->confidenceValue($zone['confidence'] ?? 0.45);
            $clearance = $this->percentageValue($zone['clearance_score'] ?? 60);
            $occlusion = $this->percentageValue($zone['occlusion_score'] ?? 20);
            $surfaceScore = $surface === $preferredSurface ? 35 : ($preferredSurface === 'floor' && $surface === 'wall-floor' ? 30 : 0);
            $typeScore = $this->zoneSupportsType($suitableTypes->all(), $type) ? 25 : 0;
            $dimensionScore = $this->dimensionScore($product, $roomWidth, $roomDepth, $zone);
            $placementWidth = $this->visualWidthForZone($analysis, $zone, $surface, $type, $product, $roomWidth, $roomDepth);
            $placementDepth = $this->renderDepth($product, $roomDepth, $placementWidth);
            $validFloorFootprint = $this->validFloorFootprint($analysis, $zone, $surface, $placementWidth, $placementDepth);
            $collision = $this->collidesWithExcludedRegion($analysis, $zone, $type, $placementWidth, $placementDepth);
            $score = ($confidence * 20) + $surfaceScore + $typeScore + ($clearance * .12) + ((100 - $occlusion) * .05) + $dimensionScore;

            return [
                'zone' => $zone,
                'placement_width' => $placementWidth,
                'score' => round(min(100, $score), 1),
                'valid' => $validFloorFootprint && ! $collision && $clearance >= 35 && $occlusion <= 65,
            ];
        })->filter(fn (array $candidate) => $candidate['valid'])->sortByDesc('score')->values();

        if ($ranked->isNotEmpty()) {
            $best = $ranked->first();
            $zone = $best['zone'];
            $score = (float) $best['score'];
            $quad = str_contains($type, 'door')
                ? $this->quad($zone['door_fit_quad_percent'] ?? null)
                : null;
            $renderWidth = $best['placement_width'];
            $needsConfirmation = false;
            $fitConfidenceScore = $score;
            if (! str_contains($type, 'door') && $measurement['status'] === 'measurements-missing') {
                $fitConfidenceScore = min($fitConfidenceScore, count($this->floorPolygon($analysis)) >= 3 ? 72.0 : 42.0);
            }

            return [
                'x_percent' => $this->clamp($zone['anchor_x_percent'] ?? 50, 5, 95),
                'y_percent' => $this->clamp($zone['anchor_y_percent'] ?? 86, 30, 96),
                'max_width_percent' => $renderWidth,
                'floor_detected' => count($this->floorPolygon($analysis)) >= 3,
                'rotation_deg' => $this->clamp($zone['rotation_deg'] ?? 0, -18, 18),
                'perspective_deg' => $this->clamp($zone['perspective_deg'] ?? 0, -24, 24),
                'flip' => filter_var($zone['flip'] ?? false, FILTER_VALIDATE_BOOLEAN) ? -1 : 1,
                'quad_percent' => $quad,
                'surface' => $zone['surface'] ?? $preferredSurface,
                'confidence' => $fitConfidenceScore >= 78 ? 'high' : ($fitConfidenceScore >= 58 ? 'medium' : 'low'),
                'confidence_score' => round($fitConfidenceScore, 1),
                'score' => $score,
                'reason' => trim((string) ($zone['reason'] ?? 'Best clear zone for the selected furniture type.')),
                'needs_confirmation' => $needsConfirmation,
                'source' => $zone['_source'] ?? 'candidate-zone',
                'renderable' => true,
                'validation_status' => ($score < 70 || $measurement['status'] !== 'measured-fit') ? 'advisory' : 'supported',
                'measurement_status' => $measurement['status'],
                'fit_basis' => $measurement['status'] === 'measured-fit' ? 'detected-zone-and-room-measurements' : 'detected-zone-only',
                'validation_messages' => $measurement['messages'],
            ];
        }

        if (str_contains($type, 'door')) {
            $detected = collect($analysis['openings'] ?? [])->concat(data_get($analysis, 'vision_geometry.openings', []))
                ->contains(fn ($opening) => is_array($opening) && preg_match('/door|entrance|entry|archway|closet|passage/i', ($opening['type'] ?? '').' '.($opening['label'] ?? '')));
            return $this->blockedPlacement(
                $preferredSurface,
                $detected ? 'missing-door-corners' : 'no-opening',
                $detected ? 'A doorway was detected, but its four room-facing fit corners are missing or invalid. Choose a clearer photo showing the full doorway to obtain usable corners.' : 'No reliable doorway opening was detected. FurniCraft will not place a door at an invented position; choose a clearer photo that shows the full frame.',
                $measurement,
            );
        }

        return $this->fallbackPlacement($analysis, $product, $type, $preferredSurface, $measurement);
    }

    private function preferredSurface(string $type): string
    {
        return match ($this->furnitureFamily($type)) {
            'door' => 'opening',
            'bed', 'storage' => 'wall-floor',
            default => 'floor',
        };
    }

    private function furnitureFamily(string $type): string
    {
        $type = strtolower(trim($type));

        return match (true) {
            preg_match('/door|doorway|entry|entrance/', $type) === 1 => 'door',
            preg_match('/dining/', $type) === 1 => 'dining',
            preg_match('/bed|bunk|double.deck/', $type) === 1 => 'bed',
            preg_match('/cabinet|wardrobe|dresser|shelf|storage/', $type) === 1 => 'storage',
            preg_match('/sala|sofa|couch|sectional|living.room/', $type) === 1 => 'living-set',
            preg_match('/chair|stool|bench|seating/', $type) === 1 => 'seating',
            preg_match('/table|desk/', $type) === 1 => 'table',
            default => 'floor',
        };
    }

    private function zoneSupportsType(array $suitableTypes, string $type): bool
    {
        if ($suitableTypes === []) {
            return true;
        }

        $family = $this->furnitureFamily($type);

        foreach ($suitableTypes as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate === '' || $this->furnitureFamily($candidate) === $family) {
                return true;
            }

            if ($family === 'living-set' && preg_match('/living|lounge|receiving/', $candidate)) {
                return true;
            }
            if ($family === 'dining' && preg_match('/table|chair|seating/', $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function dimensionScore(FurnitureProduct $product, ?float $roomWidth, ?float $roomDepth, array $zone): float
    {
        if (! $roomWidth || ! $roomDepth || ! $product->width_cm || ! $product->depth_cm) {
            return 4;
        }

        $fitsRoom = ((float) $product->width_cm / 100) <= $roomWidth * .85
            && ((float) $product->depth_cm / 100) <= $roomDepth * .85;
        $requiredWidth = ((float) $product->width_cm / ($roomWidth * 100)) * 100;
        $fitsZone = $requiredWidth <= (float) ($zone['max_width_percent'] ?? 90);

        return $fitsRoom && $fitsZone ? 8 : 0;
    }

    private function measurementAssessment(FurnitureProduct $product, ?float $roomWidth, ?float $roomDepth, string $type): array
    {
        if (! $roomWidth || ! $roomDepth || ! $product->width_cm || ! $product->depth_cm) {
            return ['status' => 'measurements-missing', 'messages' => ['Add actual room measurements to validate physical scale.']];
        }

        if (str_contains($type, 'door')) {
            return ['status' => 'opening-measurements-required', 'messages' => ['Room dimensions are known, but doorway width and height must still be measured before purchase or installation.']];
        }

        $fits = ((float) $product->width_cm / 100) <= $roomWidth * .85
            && ((float) $product->depth_cm / 100) <= $roomDepth * .85;

        return $fits
            ? ['status' => 'measured-fit', 'messages' => ['Catalog dimensions fit within 85% of the supplied room width and depth.']]
            : ['status' => 'does-not-fit', 'messages' => ['Catalog dimensions exceed the usable supplied room measurements.']];
    }

    private function zoneIsCompatible(array $zone, string $type, string $preferredSurface): bool
    {
        $surface = strtolower(trim((string) ($zone['surface'] ?? 'floor')));
        $suitableTypes = collect($zone['suitable_types'] ?? [])->map(fn ($value) => strtolower(trim((string) $value)));
        $typeAllowed = $this->zoneSupportsType($suitableTypes->all(), $type);

        if (! $typeAllowed) {
            return false;
        }

        if (str_contains($type, 'door')) {
            return ! $this->isWindowZone($zone)
                && ($zone['frame_complete'] ?? true) !== false
                && $surface === 'opening'
                && $this->quad($zone['door_fit_quad_percent'] ?? null) !== null;
        }

        if ($surface === 'opening') {
            return false;
        }

        if ($this->furnitureFamily($type) === 'living-set') {
            return in_array($surface, ['floor', 'wall-floor'], true);
        }

        return $surface === $preferredSurface || ($preferredSurface === 'floor' && $surface === 'wall-floor');
    }

    private function isWindowZone(array $zone): bool
    {
        $description = strtolower(implode(' ', array_filter([
            $zone['type'] ?? null,
            $zone['label'] ?? null,
            $zone['opening_label'] ?? null,
            $zone['region_label'] ?? null,
            $zone['reason'] ?? null,
        ], fn ($value) => is_string($value) && trim($value) !== '')));

        return (bool) preg_match('/window|skylight|clerestory|transom|fenestration/', $description);
    }
    private function renderDepth(FurnitureProduct $product, ?float $roomDepth, float $width): float
    {
        return ($roomDepth && $product->depth_cm)
            ? $this->clamp(((float) $product->depth_cm / ($roomDepth * 100)) * 100, 6, 35)
            : max(8, $width * .3);
    }

    private function validFloorFootprint(array $analysis, array $zone, string $surface, float $width, float $depth): bool
    {
        if (! in_array($surface, ['floor', 'wall-floor'], true)) {
            return true;
        }

        $floor = $this->floorPolygon($analysis);
        if (count($floor) < 3) {
            return true;
        }

        $x = (float) ($zone['anchor_x_percent'] ?? 50);
        $y = (float) ($zone['anchor_y_percent'] ?? 86);
        $rect = [$x - ($width / 2), $y - $depth, $x + ($width / 2), $y];

        return $this->rectangleInsidePolygon($rect, $floor);
    }

    private function collidesWithExcludedRegion(array $analysis, array $zone, string $type, float $width, float $depth): bool
    {
        $x = (float) ($zone['anchor_x_percent'] ?? 50);
        $y = (float) ($zone['anchor_y_percent'] ?? 86);
        $rect = [$x - ($width / 2), $y - $depth, $x + ($width / 2), $y];
        $geometry = is_array($analysis['vision_geometry'] ?? null) ? $analysis['vision_geometry'] : [];
        $regions = collect($analysis['obstacles'] ?? [])
            ->concat($analysis['blocked_zones'] ?? [])
            ->concat($geometry['blocked_zones'] ?? []);

        if (str_contains($type, 'door')) {
            // A validated door replaces its own opening. Floor footprints, door swing and
            // walkways describe floor furniture clearance, not the installation plane.
            return false;
        } else {
            $regions = $regions->concat($analysis['openings'] ?? [])->concat($geometry['openings'] ?? []);
        }

        if ($regions->contains(fn ($region) => is_array($region)
            && $this->polygonIntersectsRectangle($this->regionPolygon($region), $rect))) {
            return true;
        }

        $existingFurniture = collect($analysis['existing_furniture'] ?? [])
            ->concat($geometry['existing_furniture'] ?? []);

        return $existingFurniture->contains(fn ($item) => is_array($item)
            && ! (str_contains($type, 'door') && preg_match('/door[_\s-]*leaf/i', (string) ($item['label'] ?? '')))
            && $this->polygonIntersectsRectangle($this->regionPolygon($item), $rect));
    }

    private function renderWidth(FurnitureProduct $product, ?float $roomWidth, array $zone): float
    {
        $capacity = $this->clamp($zone['max_width_percent'] ?? 65, 1, 90);
        if (str_contains(strtolower($product->recommendationType()), 'door')) {
            return $capacity;
        }

        $catalogWidth = is_numeric($product->width_cm) ? (float) $product->width_cm : null;
        $roomRelativeWidth = $catalogWidth && $roomWidth
            ? $catalogWidth / $roomWidth
            : null;

        if ($roomRelativeWidth !== null) {
            return $this->clamp(min($capacity * .96, $roomRelativeWidth), 1, 90);
        }

        return $this->clamp($capacity * .90, 1, 90);
    }

    private function visualWidthForZone(array $analysis, array $zone, string $surface, string $type, FurnitureProduct $product, ?float $roomWidth, ?float $roomDepth): float
    {
        $baseline = $this->renderWidth($product, $roomWidth, $zone);
        if ($roomWidth !== null || $surface === 'opening') return $baseline;

        $capacity = $this->clamp($zone['max_width_percent'] ?? 65, 1, 90);
        foreach ([$capacity, $capacity * .95] as $width) {
            if ($width <= $baseline) continue;
            $depth = $this->renderDepth($product, $roomDepth, $width);
            if ($this->validFloorFootprint($analysis, $zone, $surface, $width, $depth)
                && ! $this->collidesWithExcludedRegion($analysis, $zone, $type, $width, $depth)) {
                return $width;
            }
        }

        return $baseline;
    }

    private function openingZones(array $analysis): array
    {
        return collect($analysis['openings'] ?? [])->concat(data_get($analysis, 'vision_geometry.openings', []))->map(function ($opening) {
            if (! is_array($opening)) return null;
            if (($opening['frame_complete'] ?? true) === false) return null;
            $label = trim(($opening['type'] ?? '').' '.($opening['label'] ?? ''));
            if ($this->isWindowZone(['label' => $label]) || ! preg_match('/door|doorway|entrance|entry|passage|archway|closet/', strtolower($label))) {
                return null;
            }

            $fit = $this->quad($opening['door_fit_quad_percent'] ?? null);
            if (! $fit) return null;
            $points = collect($fit);

            $minimumX = (float) $points->min(fn ($point) => $point[0]);
            $maximumX = (float) $points->max(fn ($point) => $point[0]);
            $maximumY = (float) $points->max(fn ($point) => $point[1]);

            return [
                'surface' => 'opening',
                'anchor_x_percent' => ($minimumX + $maximumX) / 2,
                'anchor_y_percent' => $maximumY,
                'max_width_percent' => max(1, $maximumX - $minimumX),
                'rotation_deg' => 0,
                'perspective_deg' => 0,
                'flip' => false,
                'suitable_types' => ['door'],
                'clearance_score' => 92,
                'occlusion_score' => 5,
                'confidence' => $analysis['scene_confidence'] ?? 'medium',
                'reason' => 'Aligned to the detected ' . $label . ' frame.',
                '_source' => 'scene-opening',
                'door_fit_quad_percent' => $this->quad($points->values()->all()),
            ];
        })->filter()->values()->all();
    }

    private function fallbackPlacement(array $analysis, FurnitureProduct $product, string $type, string $surface, array $measurement): array
    {
        $defaults = match ($this->furnitureFamily($type)) {
            'door' => [88, 76, 24],
            'storage' => [70, 88, 36],
            'bed' => [36, 88, 58],
            'living-set' => [64, 88, 68],
            'seating' => [42, 87, 28],
            'dining' => [52, 87, 58],
            'table' => [56, 86, 42],
            default => [56, 86, 48],
        };

        $floor = $this->floorPolygon($analysis);
        $floorFit = $this->fallbackFloorFit($analysis, $product, $type, $surface, (float) $defaults[2]);
        if (count($floor) < 3) {
            return $this->blockedPlacement(
                $surface,
                'placement-geometry-missing',
                'No validated floor placement area is available for this furniture. FurniCraft did not guess a position. Re-analyze a clear photo that shows the usable floor or choose another photo.',
                $measurement,
            );
        }
        if ($floorFit === null) {
            return $this->blockedPlacement(
                $surface,
                'no-clear-space',
                'No clear area can hold this furniture preview without crossing a detected floor boundary or obstacle. Try a clearer photo showing more of the room.',
                $measurement,
            );
        }

        return [
            'x_percent' => $floorFit['x'],
            'y_percent' => $floorFit['y'],
            'max_width_percent' => $floorFit['width'],
            'floor_detected' => count($floor) >= 3,
            'rotation_deg' => 0,
            'perspective_deg' => 0,
            'flip' => 1,
            'quad_percent' => null,
            'surface' => $surface,
            'confidence' => 'low',
            'score' => $floorFit ? 38 : 30,
            'reason' => 'Automatically fitted inside the visible floor area detected in this room image. This is a 2D planning estimate; verify actual dimensions.',
            'needs_confirmation' => false,
            'source' => 'image-floor-fallback',
            'renderable' => true,
            'validation_status' => 'advisory',
            'measurement_status' => $measurement['status'],
            'fit_basis' => 'detected-floor-area',
            'validation_messages' => $measurement['messages'],
        ];
    }

    private function fallbackFloorFit(array $analysis, FurnitureProduct $product, string $type, string $surface, float $preferredWidth): ?array
    {
        $polygon = $this->floorPolygon($analysis);
        $points = collect($polygon)->filter(fn ($point) => is_array($point)
            && isset($point[0], $point[1])
            && is_numeric($point[0])
            && is_numeric($point[1]))->values()->all();

        if (count($points) < 3) {
            return null;
        }

        $minimumX = max(0, min(array_column($points, 0)));
        $maximumX = min(100, max(array_column($points, 0)));
        $minimumY = max(0, min(array_column($points, 1)));
        $maximumY = min(100, max(array_column($points, 1)));
        $centerX = ($minimumX + $maximumX) / 2;
        $targetY = $minimumY + (($maximumY - $minimumY) * 0.78);
        $maximumWidth = min(90, $preferredWidth, ($maximumX - $minimumX) * .78);
        if ($maximumWidth < 15) return null;

        // Check the same geometry and collision rules as AI zones, not only the anchor point.
        // A bounded search can reduce a visual estimate without inventing a tiny guaranteed fit.
        $widths = array_unique(array_map(fn ($factor) => $this->clamp(max(15, $maximumWidth * $factor), 15, 90), [1, .85, .7, .55]));
        foreach ($widths as $width) {
            $depth = $this->renderDepth($product, null, $width);
            foreach ([0, -.1, .1, -.2, .2, -.3, .3, -.4, -.5, -.6] as $yOffset) {
                $y = $this->clamp($targetY + (($maximumY - $minimumY) * $yOffset), 30, 96);
                foreach ([0, -.1, .1, -.2, .2, -.3, .3, -.4, .4] as $xOffset) {
                    $x = $this->clamp($centerX + (($maximumX - $minimumX) * $xOffset), 5, 95);
                    $zone = ['anchor_x_percent' => $x, 'anchor_y_percent' => $y];
                    if (! $this->validFloorFootprint($analysis, $zone, $surface, $width, $depth)
                        || $this->collidesWithExcludedRegion($analysis, $zone, $type, $width, $depth)) continue;
                    return [
                        'x' => $x,
                        'y' => $y,
                        'width' => $width,
                    ];
                }
            }
        }

        return null;
    }

    private function floorPolygon(array $analysis): array
    {
        $visionFloor = $this->geometryPolygon(data_get($analysis, 'vision_geometry.floor_polygon', []));
        if (count($visionFloor) >= 3) {
            return $visionFloor;
        }

        return $this->geometryPolygon($analysis['floor_polygon'] ?? []);
    }

    private function blockedPlacement(string $surface, string $status, string $reason, array $measurement): array
    {
        return [
            'x_percent' => 50.0, 'y_percent' => 86.0, 'max_width_percent' => 30.0,
            'rotation_deg' => 0.0, 'perspective_deg' => 0.0, 'flip' => 1,
            'quad_percent' => null, 'surface' => $surface, 'confidence' => 'low', 'score' => 0.0,
            'reason' => $reason, 'needs_confirmation' => true, 'source' => 'placement-blocked',
            'renderable' => false, 'validation_status' => $status,
            'measurement_status' => $measurement['status'], 'fit_basis' => 'insufficient-validated-space',
            'validation_messages' => array_values(array_unique(array_merge([$reason], $measurement['messages']))),
        ];
    }

    private function geometryPolygon(mixed $polygon): array
    {
        if (! is_array($polygon)) {
            return [];
        }

        $points = collect($polygon)->filter(fn ($point) => is_array($point)
            && isset($point[0], $point[1])
            && is_numeric($point[0])
            && is_numeric($point[1]))->values();
        if ($points->count() < 3) {
            return [];
        }

        $scale = $points->flatten()->map(fn ($value) => abs((float) $value))->max() <= 1.001 ? 100 : 1;

        return $points->map(fn ($point) => [(float) $point[0] * $scale, (float) $point[1] * $scale])->all();
    }

    private function regionPolygon(array $region): array
    {
        $polygon = $this->geometryPolygon($region['polygon'] ?? []);
        if (count($polygon) >= 3) {
            return $polygon;
        }
        $fit = DoorwayGeometry::quad($region['door_fit_quad_percent'] ?? null);
        if ($fit) return $fit;

        $bbox = $region['bbox'] ?? null;
        if (! is_array($bbox) || count($bbox) !== 4 || ! collect($bbox)->every(fn ($value) => is_numeric($value))) {
            return [];
        }

        [$x1, $y1, $x2, $y2] = array_map('floatval', array_values($bbox));
        if (max(abs($x1), abs($y1), abs($x2), abs($y2)) <= 1.001) {
            [$x1, $y1, $x2, $y2] = [$x1 * 100, $y1 * 100, $x2 * 100, $y2 * 100];
        }

        $left = min($x1, $x2);
        $right = max($x1, $x2);
        $top = min($y1, $y2);
        $bottom = max($y1, $y2);

        return $right > $left && $bottom > $top
            ? [[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom]]
            : [];
    }

    private function rectangleInsidePolygon(array $rect, array $polygon): bool
    {
        [$left, $top, $right, $bottom] = $rect;
        if ($left < 0 || $top < 0 || $right > 100 || $bottom > 100 || $right <= $left || $bottom <= $top) {
            return false;
        }

        $corners = [[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom]];
        foreach ($corners as $index => $start) {
            $end = $corners[($index + 1) % count($corners)];
            for ($step = 0; $step <= 8; $step++) {
                $ratio = $step / 8;
                $point = [$start[0] + ($end[0] - $start[0]) * $ratio, $start[1] + ($end[1] - $start[1]) * $ratio];
                if (! $this->pointInOrOnPolygon($point[0], $point[1], $polygon)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function polygonIntersectsRectangle(array $polygon, array $rect): bool
    {
        if (count($polygon) < 3) {
            return false;
        }

        [$left, $top, $right, $bottom] = $rect;
        $inRect = fn (array $point) => $point[0] >= $left && $point[0] <= $right && $point[1] >= $top && $point[1] <= $bottom;
        if (collect($polygon)->contains($inRect)) {
            return true;
        }

        $corners = [[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom]];
        if (collect($corners)->contains(fn ($point) => $this->pointInOrOnPolygon($point[0], $point[1], $polygon))) {
            return true;
        }

        foreach ($polygon as $index => $start) {
            $end = $polygon[($index + 1) % count($polygon)];
            for ($edge = 0; $edge < count($corners); $edge++) {
                if ($this->segmentsIntersect($start, $end, $corners[$edge], $corners[($edge + 1) % count($corners)])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pointInOrOnPolygon(float $x, float $y, array $polygon): bool
    {
        if ($this->pointInPolygon($x, $y, $polygon)) {
            return true;
        }

        foreach ($polygon as $index => $start) {
            $end = $polygon[($index + 1) % count($polygon)];
            if (abs($this->orientation($start, $end, [$x, $y])) < 1e-7
                && $x >= min($start[0], $end[0]) - 1e-7 && $x <= max($start[0], $end[0]) + 1e-7
                && $y >= min($start[1], $end[1]) - 1e-7 && $y <= max($start[1], $end[1]) + 1e-7) {
                return true;
            }
        }

        return false;
    }

    private function segmentsIntersect(array $a, array $b, array $c, array $d): bool
    {
        $o1 = $this->orientation($a, $b, $c);
        $o2 = $this->orientation($a, $b, $d);
        $o3 = $this->orientation($c, $d, $a);
        $o4 = $this->orientation($c, $d, $b);

        if ((($o1 > 0 && $o2 < 0) || ($o1 < 0 && $o2 > 0))
            && (($o3 > 0 && $o4 < 0) || ($o3 < 0 && $o4 > 0))) {
            return true;
        }

        return (abs($o1) < 1e-7 && $this->pointOnSegment($c, $a, $b))
            || (abs($o2) < 1e-7 && $this->pointOnSegment($d, $a, $b))
            || (abs($o3) < 1e-7 && $this->pointOnSegment($a, $c, $d))
            || (abs($o4) < 1e-7 && $this->pointOnSegment($b, $c, $d));
    }

    private function pointOnSegment(array $point, array $start, array $end): bool
    {
        return abs($this->orientation($start, $end, $point)) < 1e-7
            && $point[0] >= min($start[0], $end[0]) - 1e-7 && $point[0] <= max($start[0], $end[0]) + 1e-7
            && $point[1] >= min($start[1], $end[1]) - 1e-7 && $point[1] <= max($start[1], $end[1]) + 1e-7;
    }

    private function orientation(array $a, array $b, array $c): float
    {
        return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
    }

    private function pointInPolygon(float $x, float $y, array $polygon): bool
    {
        $inside = false;
        $points = array_values(array_filter($polygon, fn ($point) => is_array($point) && isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])));
        $count = count($points);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = [(float) $points[$i][0], (float) $points[$i][1]];
            [$xj, $yj] = [(float) $points[$j][0], (float) $points[$j][1]];
            $crosses = (($yi > $y) !== ($yj > $y)) && ($x < (($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 0.00001)) + $xi);
            if ($crosses) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function confidenceValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return min(1, max(0, (float) $value > 1 ? (float) $value / 100 : (float) $value));
        }

        return match (strtolower((string) $value)) {
            'high' => .9,
            'medium' => .68,
            default => .42,
        };
    }

    private function percentageValue(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0;

        return min(100, max(0, $number <= 1 ? $number * 100 : $number));
    }

    private function clamp(mixed $value, float $minimum, float $maximum): float
    {
        $number = is_numeric($value) ? (float) $value : $minimum;

        return round(min($maximum, max($minimum, $number)), 1);
    }

    private function quad(mixed $quad): ?array
    {
        return DoorwayGeometry::quad($quad);
    }

}
