<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Services\FurniturePlacementService;
use Tests\TestCase;

class AutomaticPlacementSafetyTest extends TestCase
{
    public function test_fully_blocked_detected_floor_never_forces_a_fallback_placement(): void
    {
        $placement = app(FurniturePlacementService::class)->forProduct([
            'floor_polygon' => [[0, 30], [100, 30], [100, 100], [0, 100]],
            'vision_geometry' => ['blocked_zones' => [['polygon' => [[0, .3], [1, .3], [1, 1], [0, 1]]]]],
        ], $this->product());

        $this->assertFalse($placement['renderable']);
        $this->assertSame('no-clear-space', $placement['validation_status']);
        $this->assertSame('placement-blocked', $placement['source']);
    }

    public function test_fallback_full_footprint_stays_out_of_a_concave_floor_notch(): void
    {
        $placement = app(FurniturePlacementService::class)->forProduct([
            // Wide top strip and two narrow lower arms; the center below y=65 is outside the room floor.
            'floor_polygon' => [[10, 40], [90, 40], [90, 95], [65, 95], [65, 65], [35, 65], [35, 95], [10, 95]],
        ], $this->product());

        $this->assertTrue($placement['renderable']);
        $this->assertSame('image-floor-fallback', $placement['source']);
        [$left, $top, $right, $bottom] = $this->footprint($placement);
        $this->assertGreaterThanOrEqual(10, $left);
        $this->assertLessThanOrEqual(90, $right);
        $this->assertGreaterThanOrEqual(40, $top);
        $this->assertLessThanOrEqual(95, $bottom);
        $this->assertTrue($bottom <= 65 || $right <= 35 || $left >= 65, 'The whole footprint must avoid the concave notch.');
    }

    public function test_fallback_moves_and_resizes_around_an_existing_piece_instead_of_overlapping_it(): void
    {
        $placement = app(FurniturePlacementService::class)->forProduct([
            'floor_polygon' => [[0, 30], [100, 30], [100, 100], [0, 100]],
            'existing_furniture' => [['label' => 'Occupied left half', 'bbox' => [0, .3, .5, 1]]],
        ], $this->product());

        $this->assertTrue($placement['renderable']);
        $this->assertSame('image-floor-fallback', $placement['source']);
        [$left, $top, $right, $bottom] = $this->footprint($placement);
        $this->assertGreaterThan(50, $left);
        $this->assertLessThanOrEqual(100, $right);
        $this->assertGreaterThanOrEqual(30, $top);
        $this->assertLessThanOrEqual(100, $bottom);
    }

    public function test_clear_detected_floor_still_allows_a_photo_based_visual_estimate(): void
    {
        $placement = app(FurniturePlacementService::class)->forProduct([
            'floor_polygon' => [[10, 40], [90, 40], [90, 95], [10, 95]],
        ], $this->product());

        $this->assertTrue($placement['renderable']);
        $this->assertSame('detected-floor-area', $placement['fit_basis']);
        [$left, $top, $right, $bottom] = $this->footprint($placement);
        $this->assertGreaterThanOrEqual(10, $left);
        $this->assertGreaterThanOrEqual(40, $top);
        $this->assertLessThanOrEqual(90, $right);
        $this->assertLessThanOrEqual(95, $bottom);
    }

    public function test_missing_floor_geometry_does_not_invent_a_type_based_estimate(): void
    {
        $placement = app(FurniturePlacementService::class)->forProduct([], $this->product());

        $this->assertFalse($placement['renderable']);
        $this->assertSame('placement-blocked', $placement['source']);
        $this->assertSame('low', $placement['confidence']);
        $this->assertSame('placement-geometry-missing', $placement['validation_status']);
    }

    public function test_sofa_footprint_can_stand_in_front_of_a_window_and_wall(): void
    {
        $placement = app(FurniturePlacementService::class)->forProduct([
            'vision_geometry' => [
                'floor_polygon' => [[0,.61],[.28,.66],[1,.66],[1,1],[0,1]],
                'blocked_zones' => [['reason' => 'existing_furniture', 'polygon' => [[.58,.55],[1,.55],[1,.66],[.58,.66]]]],
                'openings' => [['type' => 'window', 'bbox' => [.59,.23,.98,.55]]],
            ],
            'candidate_zones' => [[
                'surface' => 'floor', 'suitable_types' => ['sofa'], 'anchor_x_percent' => 62,
                'anchor_y_percent' => 80, 'max_width_percent' => 42,
                'confidence' => 'high', 'clearance_score' => 95, 'occlusion_score' => 0,
            ]],
        ], $this->product());

        $this->assertTrue($placement['renderable']);
        $this->assertSame('candidate-zone', $placement['source']);
        $this->assertEquals(62, $placement['x_percent']);
        $this->assertEquals(80, $placement['y_percent']);
        $this->assertEquals(42, $placement['max_width_percent']);
    }

    private function product(): FurnitureProduct
    {
        return new FurnitureProduct(['category' => 'Sala Set', 'width_cm' => 220, 'depth_cm' => 90]);
    }

    private function footprint(array $placement): array
    {
        $width = (float) $placement['max_width_percent'];
        $depth = max(8, $width * .3);
        return [$placement['x_percent'] - $width / 2, $placement['y_percent'] - $depth, $placement['x_percent'] + $width / 2, $placement['y_percent']];
    }
}
