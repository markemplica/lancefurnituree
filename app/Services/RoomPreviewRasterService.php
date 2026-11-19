<?php

namespace App\Services;

use App\Models\FurnitureProduct;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RoomPreviewRasterService
{
    public function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagepng');
    }

    /** Normalize dimensions, orientation and metadata where GD is available. */
    public function normalize(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || ! isset($info[0], $info[1])) throw new RuntimeException('invalid_image');
        $width = (int) $info[0];
        $height = (int) $info[1];
        if (max($width, $height) < (int) config('roompreview.min_dimension')) throw new RuntimeException('image_too_small');
        if ($width * $height > (int) config('roompreview.max_pixels')) throw new RuntimeException('image_pixel_limit');
        if (! $this->available()) return ['bytes' => $bytes, 'mime' => $info['mime'] ?? 'image/jpeg', 'width' => $width, 'height' => $height, 'quality' => null, 'normalized' => false];

        $image = @imagecreatefromstring($bytes);
        if (! $image) throw new RuntimeException('invalid_image');
        $image = RoomImageOrientation::apply($image, $bytes);
        $width = imagesx($image); $height = imagesy($image);
        $max = (int) config('roompreview.max_dimension', 1600);
        $scale = min(1, $max / max($width, $height));
        if ($scale < 1) {
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }
        $quality = $this->quality($image, $width, $height);
        ob_start();
        imagepng($image);
        $normalized = (string) ob_get_clean();
        imagedestroy($image);
        if (strlen($normalized) > (int) config('roompreview.max_upload_bytes')) throw new RuntimeException('normalized_image_too_large');
        return ['bytes' => $normalized, 'mime' => 'image/png', 'width' => $width, 'height' => $height, 'quality' => $quality, 'normalized' => true];
    }

    /** Deterministic compositing fallback using only trusted catalog image bytes and server geometry. */
    public function composite(string $roomBytes, FurnitureProduct $product, array $placement): ?array
    {
        if (! $this->available() || ($placement['renderable'] ?? true) === false) return null;
        $room = @imagecreatefromstring($roomBytes);
        $furnitureBytes = $this->catalogImageBytes($product);
        $item = $furnitureBytes ? @imagecreatefromstring($furnitureBytes) : false;
        if (! $room || ! $item) {
            if ($room) imagedestroy($room);
            if ($item) imagedestroy($item);
            return null;
        }
        $room = RoomImageOrientation::apply($room, $roomBytes);
        $item = RoomImageOrientation::apply($item, $furnitureBytes);
        $canvasWidth = imagesx($room);
        $canvasHeight = imagesy($room);
        imagepalettetotruecolor($item);
        $cropped = $this->cropTransparentBorder($item);
        if ($cropped === null) {
            imagedestroy($item);
            imagedestroy($room);
            return null;
        }
        if ($cropped !== $item) {
            imagedestroy($item);
            $item = $cropped;
        }
        $opening = ($placement['surface'] ?? null) === 'opening'
            || (filled($product->category) && str_contains(strtolower($product->recommendationType()), 'door'));
        if (! $opening && ($placement['surface'] ?? 'floor') !== 'wall') {
            $placement['max_width_percent'] = 100 * app(RoomPreviewScaleService::class)->limitWidth(
                (float) ($placement['max_width_percent'] ?? 35) / 100,
                $product->recommendationType(), imagesx($item) / imagesy($item), $canvasWidth / $canvasHeight,
                (bool) ($placement['floor_detected'] ?? (($placement['source'] ?? '') !== 'type-fallback')),
            );
        }
        $quad = $opening
            ? $this->openingQuad($placement['quad_percent'] ?? null, $canvasWidth, $canvasHeight)
            : $this->furnitureQuad($placement, imagesx($item), imagesy($item), $canvasWidth, $canvasHeight);
        if ($quad === null) {
            imagedestroy($item);
            imagedestroy($room);
            return null;
        }
        $left = max(0, (int) floor(min(array_column($quad, 0))));
        $top = max(0, (int) floor(min(array_column($quad, 1))));
        $right = min($canvasWidth, (int) ceil(max(array_column($quad, 0))));
        $bottom = min($canvasHeight, (int) ceil(max(array_column($quad, 1))));
        $guardBounds = [$left, $top, $right, $bottom];
        imagealphablending($room, true);
        $flip = ($placement['flip'] ?? 1) === -1 || ($placement['flip'] ?? false) === true;
        app(RoomPreviewLightingService::class)->apply($item, $room, $flip);
        $layer = imagecreatetruecolor($canvasWidth, $canvasHeight);
        imagealphablending($layer, false); imagesavealpha($layer, true);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        $this->drawProjectedImage($layer, $item, $quad, $flip);
        $visible = 0;
        for ($y = $top; $y < $bottom; $y++) for ($x = $left; $x < $right; $x++) {
            if (((imagecolorat($layer, $x, $y) >> 24) & 127) <= 119) $visible++;
        }
        if ($visible < 4) {
            imagedestroy($layer); imagedestroy($item); imagedestroy($room);
            return null;
        }
        // Doors fill detected openings; a floor shadow would invent another object below the frame.
        if (! $opening && ($placement['surface'] ?? 'floor') !== 'wall') {
            $guardBounds = $this->contactShadows($room, $item, $quad, $flip, $guardBounds);
        }
        imagecopy($room, $layer, 0, 0, 0, 0, $canvasWidth, $canvasHeight);
        imagedestroy($layer);
        ob_start();
        imagepng($room);
        $bytes = (string) ob_get_clean();
        imagedestroy($item);
        imagedestroy($room);
        return ['bytes' => $bytes, 'mime' => 'image/png', 'bounds' => [$left, $top, $right, $bottom], 'guard_bounds' => $guardBounds, 'visible_product_pixels' => $visible];
    }

    private function contactShadows(\GdImage $room, \GdImage $item, array $quad, bool $flip, array $guard): array
    {
        $sample = imagecreatetruecolor(160, 160);
        imagealphablending($sample, false); imagesavealpha($sample, true);
        imagecopyresampled($sample, $item, 0, 0, 0, 0, 160, 160, imagesx($item), imagesy($item));
        $profile = array_fill(0, 160, null);
        for ($x = 0; $x < 160; $x++) {
            for ($y = 159; $y >= 58; $y--) {
                if (((imagecolorat($sample, $x, $y) >> 24) & 127) <= 47
                    && ((imagecolorat($sample, $x, $y - 1) >> 24) & 127) <= 47
                    && ((imagecolorat($sample, $x, $y - 2) >> 24) & 127) <= 47) {
                    $profile[$x] = $y; break;
                }
            }
        }
        imagedestroy($sample);
        $contacts = [];
        for ($x = 0; $x < 160;) {
            if ($profile[$x] === null) { $x++; continue; }
            $start = $x; $low = $high = $profile[$x];
            while ($x + 1 < 160 && $profile[$x + 1] !== null && max($high, $profile[$x + 1]) - min($low, $profile[$x + 1]) <= 2) {
                $x++; $low = min($low, $profile[$x]); $high = max($high, $profile[$x]);
            }
            $end = $x++;
            if ($end - $start < 1) continue;
            $recessed = false;
            for ($i = max(0, $start - 6); $i <= min(159, $end + 6); $i++) {
                if ($profile[$i] !== null && $profile[$i] > $high + 3) { $recessed = true; break; }
            }
            if (! $recessed) $contacts[] = ['x' => ($start + $end + 1) / 320, 'y' => ($high + 1) / 160, 'width' => ($end - $start + 1) / 160];
        }
        usort($contacts, fn ($a, $b) => $b['y'] <=> $a['y']);
        $itemWidth = hypot($quad[1][0] - $quad[0][0], $quad[1][1] - $quad[0][1]);
        foreach (array_slice($contacts, 0, 24) as $contact) {
            $u = $flip ? 1 - $contact['x'] : $contact['x']; $v = $contact['y'];
            [$cx, $cy] = $this->projectQuad($quad, $u, $v);
            $rx = max(2, min($itemWidth * .08, $contact['width'] * $itemWidth * .9 + 1));
            $ry = max(1.2, $rx * .24); $cy += $ry * .15;
            $left = max(0, (int) floor($cx - $rx)); $right = min(imagesx($room) - 1, (int) ceil($cx + $rx));
            $top = max(0, (int) floor($cy - $ry)); $bottom = min(imagesy($room) - 1, (int) ceil($cy + $ry));
            for ($y = $top; $y <= $bottom; $y++) for ($x = $left; $x <= $right; $x++) {
                $distance = hypot(($x - $cx) / $rx, ($y - $cy) / $ry);
                if ($distance >= 1) continue;
                $opacity = $distance < .35 ? .32 - .14 * $distance / .35 : .18 * (1 - $distance) / .65;
                imagesetpixel($room, $x, $y, imagecolorallocatealpha($room, 25, 20, 16, (int) round(127 * (1 - $opacity))));
            }
            $guard = [min($guard[0], $left), min($guard[1], $top), max($guard[2], $right + 1), max($guard[3], $bottom + 1)];
        }
        return $guard;
    }

    /** Only alpha-transparent padding is removed; opaque backgrounds and product colours are retained. */
    private function cropTransparentBorder(\GdImage $image): ?\GdImage
    {
        $width = imagesx($image); $height = imagesy($image);
        // Most catalog JPEGs are opaque; four opaque corners already fix all crop bounds.
        $corners = [[0, 0], [$width - 1, 0], [$width - 1, $height - 1], [0, $height - 1]];
        if (count(array_filter($corners, fn ($point) => ((imagecolorat($image, $point[0], $point[1]) >> 24) & 127) < 127)) === 4) return $image;
        $left = $width; $top = $height; $right = -1; $bottom = -1;
        for ($y = 0; $y < $height; $y++) for ($x = 0; $x < $width; $x++) {
            if (((imagecolorat($image, $x, $y) >> 24) & 127) === 127) continue;
            $left = min($left, $x); $right = max($right, $x);
            $top = min($top, $y); $bottom = max($bottom, $y);
        }
        if ($right < $left) return null;
        if ($left === 0 && $top === 0 && $right === $width - 1 && $bottom === $height - 1) return $image;
        $crop = imagecrop($image, ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1]);
        if ($crop) imagesavealpha($crop, true);
        return $crop ?: $image;
    }

    private function openingQuad(mixed $points, int $width, int $height): ?array
    {
        $points = DoorwayGeometry::quad($points);
        if (! is_array($points) || count($points) !== 4) return null;
        $quad = [];
        foreach (array_values($points) as $point) {
            if (! is_array($point) || ! isset($point[0], $point[1]) || ! is_numeric($point[0]) || ! is_numeric($point[1])) return null;
            [$x, $y] = [(float) $point[0], (float) $point[1]];
            if (! is_finite($x) || ! is_finite($y) || $x < 0 || $x > 100 || $y < 0 || $y > 100) return null;
            $quad[] = [$x * $width / 100, $y * $height / 100];
        }
        // Reject crossing/concave/degenerate quads rather than moving a door to an invented location.
        $sign = null;
        for ($i = 0; $i < 4; $i++) {
            [$a, $b, $c] = [$quad[$i], $quad[($i + 1) % 4], $quad[($i + 2) % 4]];
            $cross = ($b[0] - $a[0]) * ($c[1] - $b[1]) - ($b[1] - $a[1]) * ($c[0] - $b[0]);
            if (abs($cross) < 0.01 || ($sign !== null && ($cross > 0) !== $sign)) return null;
            $sign = $cross > 0;
        }
        return $quad;
    }

    private function furnitureQuad(array $placement, int $itemWidth, int $itemHeight, int $width, int $height): array
    {
        $targetWidth = max(1, $width * max(1, min(90, (float) ($placement['max_width_percent'] ?? 35))) / 100);
        $scale = min($targetWidth / $itemWidth, $width * .9 / $itemWidth, $height * .92 / $itemHeight);
        $w = $itemWidth * $scale; $h = $itemHeight * $scale;
        $rotation = deg2rad(max(-18, min(18, (float) ($placement['rotation_deg'] ?? 0))));
        $turn = sin(deg2rad(max(-24, min(24, (float) ($placement['perspective_deg'] ?? 0))))) * .18;
        if (abs($rotation) < 1e-9 && abs($turn) < 1e-9) {
            // Snap the unwarped rectangle as a unit; rounding each edge separately distorts tall cutouts.
            $aspect = $h / $w;
            $w = max(1, (int) floor($w)); $h = max(1, (int) round($w * $aspect));
            $left = (int) round(max(0, min($width - $w, $width * max(.05, min(.95, (float) ($placement['x_percent'] ?? 50) / 100)) - $w / 2)));
            $top = (int) round(max(0, min($height - $h, $height * max(.05, min(.98, (float) ($placement['y_percent'] ?? 86) / 100)) - $h)));
            return [[$left, $top], [$left + $w, $top], [$left + $w, $top + $h], [$left, $top + $h]];
        }
        $offsets = [];
        foreach ([[-$w / 2, -$h], [$w / 2, -$h], [$w / 2, 0], [-$w / 2, 0]] as [$x, $y]) {
            $depth = 1 + $turn * (2 * $x / $w);
            $x /= $depth; $y /= $depth;
            $offsets[] = [$x * cos($rotation) - $y * sin($rotation), $x * sin($rotation) + $y * cos($rotation)];
        }
        $minX = min(array_column($offsets, 0)); $maxX = max(array_column($offsets, 0));
        $minY = min(array_column($offsets, 1)); $maxY = max(array_column($offsets, 1));
        $fit = min(1, $width * .9 / max(1, $maxX - $minX), $height * .92 / max(1, $maxY - $minY));
        $centerX = max(-$minX * $fit, min($width - $maxX * $fit, $width * max(.05, min(.95, (float) ($placement['x_percent'] ?? 50) / 100))));
        $bottomY = max(-$minY * $fit, min($height - $maxY * $fit, $height * max(.05, min(.98, (float) ($placement['y_percent'] ?? 86) / 100))));
        return array_map(fn ($point) => [$centerX + $point[0] * $fit, $bottomY + $point[1] * $fit], $offsets);
    }

    /** Project source contact points with the same homography used by the image warp. */
    private function projectQuad(array $quad, float $u, float $v): array
    {
        [[$x0, $y0], [$x1, $y1], [$x2, $y2], [$x3, $y3]] = $quad;
        $dx1 = $x1 - $x2; $dx2 = $x3 - $x2; $dx3 = $x0 - $x1 + $x2 - $x3;
        $dy1 = $y1 - $y2; $dy2 = $y3 - $y2; $dy3 = $y0 - $y1 + $y2 - $y3;
        $den = $dx1 * $dy2 - $dx2 * $dy1;
        $g = abs($den) < 1e-9 ? 0 : ($dx3 * $dy2 - $dx2 * $dy3) / $den;
        $h = abs($den) < 1e-9 ? 0 : ($dx1 * $dy3 - $dx3 * $dy1) / $den;
        $den = $g * $u + $h * $v + 1;
        return [(($x1 - $x0 + $g * $x1) * $u + ($x3 - $x0 + $h * $x3) * $v + $x0) / $den,
            (($y1 - $y0 + $g * $y1) * $u + ($y3 - $y0 + $h * $y3) * $v + $y0) / $den];
    }

    /** Inverse homography maps destination pixels to the original image, avoiding gaps between triangles. */
    private function drawProjectedImage(\GdImage $canvas, \GdImage $source, array $quad, bool $flip): void
    {
        [[$x0, $y0], [$x1, $y1], [$x2, $y2], [$x3, $y3]] = $quad;
        if ($x1 > $x0 && $y3 > $y0 && abs($y0 - $y1) < 1e-6 && abs($y2 - $y3) < 1e-6 && abs($x0 - $x3) < 1e-6 && abs($x1 - $x2) < 1e-6) {
            if ($flip) imageflip($source, IMG_FLIP_HORIZONTAL);
            imagecopyresampled($canvas, $source, (int) floor($x0), (int) floor($y0), 0, 0,
                max(1, (int) ceil($x1) - (int) floor($x0)), max(1, (int) ceil($y3) - (int) floor($y0)), imagesx($source), imagesy($source));
            if ($flip) imageflip($source, IMG_FLIP_HORIZONTAL);
            return;
        }
        $dx1 = $x1 - $x2; $dx2 = $x3 - $x2; $dx3 = $x0 - $x1 + $x2 - $x3;
        $dy1 = $y1 - $y2; $dy2 = $y3 - $y2; $dy3 = $y0 - $y1 + $y2 - $y3;
        $denominator = $dx1 * $dy2 - $dx2 * $dy1;
        $g = abs($denominator) < 1e-9 ? 0 : ($dx3 * $dy2 - $dx2 * $dy3) / $denominator;
        $h = abs($denominator) < 1e-9 ? 0 : ($dx1 * $dy3 - $dx3 * $dy1) / $denominator;
        $a = $x1 - $x0 + $g * $x1; $b = $x3 - $x0 + $h * $x3; $c = $x0;
        $d = $y1 - $y0 + $g * $y1; $e = $y3 - $y0 + $h * $y3; $f = $y0;
        $inverse = [$e - $f * $h, $c * $h - $b, $b * $f - $c * $e, $f * $g - $d, $a - $c * $g, $c * $d - $a * $f, $d * $h - $e * $g, $b * $g - $a * $h, $a * $e - $b * $d];
        $left = max(0, (int) floor(min(array_column($quad, 0))));
        $top = max(0, (int) floor(min(array_column($quad, 1))));
        $right = min(imagesx($canvas), (int) ceil(max(array_column($quad, 0))));
        $bottom = min(imagesy($canvas), (int) ceil(max(array_column($quad, 1))));
        // Resample once with GD before warping to avoid aliasing when reducing large catalog photographs.
        $sample = imagecreatetruecolor(max(1, $right - $left), max(1, $bottom - $top));
        imagealphablending($sample, false); imagesavealpha($sample, true);
        imagefill($sample, 0, 0, imagecolorallocatealpha($sample, 0, 0, 0, 127));
        imagecopyresampled($sample, $source, 0, 0, 0, 0, imagesx($sample), imagesy($sample), imagesx($source), imagesy($source));
        for ($y = $top; $y < $bottom; $y++) for ($x = $left; $x < $right; $x++) {
            $px = $x + .5; $py = $y + .5;
            $den = $inverse[6] * $px + $inverse[7] * $py + $inverse[8];
            if (abs($den) < 1e-9) continue;
            $u = ($inverse[0] * $px + $inverse[1] * $py + $inverse[2]) / $den;
            $v = ($inverse[3] * $px + $inverse[4] * $py + $inverse[5]) / $den;
            if ($u < 0 || $u > 1 || $v < 0 || $v > 1) continue;
            if ($flip) $u = 1 - $u;
            $color = imagecolorat($sample, min(imagesx($sample) - 1, (int) ($u * imagesx($sample))), min(imagesy($sample) - 1, (int) ($v * imagesy($sample))));
            if ((($color >> 24) & 127) < 127) imagesetpixel($canvas, $x, $y, $color);
        }
        imagedestroy($sample);
    }

    /** Reject generated frames that materially alter pixels outside the intended item box. */
    public function pixelGuard(string $before, string $after, array $bounds): array
    {
        if (! $this->available()) return ['supported' => false, 'passed' => false, 'changed_ratio' => null];
        $a = @imagecreatefromstring($before);
        $b = @imagecreatefromstring($after);
        if (! $a || ! $b || imagesx($a) !== imagesx($b) || imagesy($a) !== imagesy($b)) {
            if ($a) imagedestroy($a);
            if ($b) imagedestroy($b);
            return ['supported' => true, 'passed' => false, 'changed_ratio' => 1.0];
        }
        [$x1, $y1, $x2, $y2] = array_map('intval', $bounds);
        $marginX = (int) ((imagesx($a)) * 0.025);
        $marginY = (int) ((imagesy($a)) * 0.025);
        $x1 -= $marginX; $x2 += $marginX; $y1 -= $marginY; $y2 += $marginY;
        $changed = 0; $sampled = 0; $itemChanges = 0;
        $step = max(1, (int) floor(max(imagesx($a), imagesy($a)) / 160));
        for ($y = 0; $y < imagesy($a); $y += $step) for ($x = 0; $x < imagesx($a); $x += $step) {
            $pa = imagecolorat($a, $x, $y); $pb = imagecolorat($b, $x, $y);
            $delta = abs((($pa >> 16) & 255) - (($pb >> 16) & 255))
                + abs((($pa >> 8) & 255) - (($pb >> 8) & 255))
                + abs(($pa & 255) - ($pb & 255));
            if ($x >= $x1 && $x <= $x2 && $y >= $y1 && $y <= $y2) {
                // A changed upper item region is required; a floor shadow alone is insufficient.
                if ($y < $y2 - ($y2 - $y1) * .15 && $delta > 45 && (($pb >> 24) & 127) <= 119) $itemChanges++;
                continue;
            }
            if ($delta > 90) $changed++;
            $sampled++;
        }
        imagedestroy($a); imagedestroy($b);
        $ratio = $sampled ? $changed / $sampled : 1.0;
        return ['supported' => true, 'passed' => $itemChanges >= 4 && $ratio <= (float) config('roompreview.pixel_difference_max', 0.05), 'changed_ratio' => round($ratio, 4), 'item_changed_samples' => $itemChanges];
    }

    public function catalogImageBytes(FurnitureProduct $product): ?string
    {
        $path = (string) $product->image_path;
        if (str_starts_with($path, 'catalog:')) $path = public_path('images/catalog/'.substr($path, 8));
        elseif (str_starts_with($path, 'demo:')) $path = public_path('images/furniture/'.substr($path, 5));
        elseif (Storage::disk('public')->exists($path)) return Storage::disk('public')->get($path);
        else return null;
        return is_file($path) ? file_get_contents($path) : null;
    }

    private function quality($image, int $width, int $height): array
    {
        $brightnessTotal = 0.0; $sampleCount = 0; $grid = [];
        $stepX = max(1, (int) floor($width / 64)); $stepY = max(1, (int) floor($height / 64));
        for ($y = 0; $y < $height; $y += $stepY) {
            $row = [];
            for ($x = 0; $x < $width; $x += $stepX) {
                $rgb = imagecolorat($image, $x, $y);
                $gray = (int) round(0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255));
                $row[] = $gray; $brightnessTotal += $gray / 255; $sampleCount++;
            }
            $grid[] = $row;
        }
        $brightness = $sampleCount ? $brightnessTotal / $sampleCount : 0.0;
        $laplacians = [];
        for ($row = 1; $row < count($grid) - 1; $row++) {
            for ($column = 1; $column < count($grid[$row]) - 1; $column++) {
                $laplacians[] = $grid[$row - 1][$column] + $grid[$row + 1][$column]
                    + $grid[$row][$column - 1] + $grid[$row][$column + 1] - 4 * $grid[$row][$column];
            }
        }
        $lapMean = count($laplacians) ? array_sum($laplacians) / count($laplacians) : 0.0;
        $lapVariance = count($laplacians)
            ? array_sum(array_map(fn ($value) => ($value - $lapMean) ** 2, $laplacians)) / count($laplacians)
            : 0.0;
        return [
            'brightness' => round($brightness, 3),
            'blur_variance' => round($lapVariance, 2),
            'usable' => $brightness >= (float) config('roompreview.brightness_min')
                && $brightness <= (float) config('roompreview.brightness_max')
                && $lapVariance >= (float) config('roompreview.blur_variance_min'),
        ];
    }
}

