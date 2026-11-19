<?php

namespace App\Services;

/** Image-space room-facing corners, never inferred from an opening's bounding box. */
class DoorwayGeometry
{
    public static function quad(mixed $points): ?array
    {
        if (! is_array($points) || count($points) !== 4) return null;
        $quad = [];
        foreach (array_values($points) as $point) {
            if (! is_array($point) || count($point) !== 2 || ! is_numeric($point[0] ?? null) || ! is_numeric($point[1] ?? null)) return null;
            $quad[] = [(float) $point[0], (float) $point[1]];
        }
        // Older saved geometry may use fractions. Convert the entire quad, not individual points.
        $scale = max(array_merge(...$quad)) <= 1 ? 100 : 1;
        foreach ($quad as &$point) {
            foreach ($point as &$value) {
                $value *= $scale;
                if (! is_finite($value) || $value < 0 || $value > 100) return null;
            }
            unset($value);
        }
        unset($point);
        for ($i = 0; $i < 4; $i++) {
            [$a, $b, $c] = [$quad[$i], $quad[($i + 1) % 4], $quad[($i + 2) % 4]];
            $cross = ($b[0] - $a[0]) * ($c[1] - $b[1]) - ($b[1] - $a[1]) * ($c[0] - $b[0]);
            if ($cross <= .001) return null;
        }
        if ($quad[0][0] >= $quad[1][0] || $quad[3][0] >= $quad[2][0]
            || $quad[0][1] + $quad[1][1] >= $quad[2][1] + $quad[3][1]) return null;
        return $quad;
    }
}
