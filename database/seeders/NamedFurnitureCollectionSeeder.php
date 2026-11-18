<?php

namespace Database\Seeders;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NamedFurnitureCollectionSeeder extends Seeder
{
    public const NAMES = [
        'Balangay Classic Sala Set',
        'Hiraya Slatted Sala Set',
        'Dalisay Cream Cushion Sala Set',
        'Royal Swan Curving Sala Set',
        'Rosas Pattern Cushion Sala Set',
        'Sinag Yellow Cushion Sala Set',
        'Dapit Dark Slatted Sala Set',
        'Silong Vertical Slat Sala Set',
        'Hapon Gray Cushion Sala Set',
        'Royal Flora Curving Dining Set',
        'Linyang Cream Inlay Dining Set',
        'Sampaguita Floral Curving Bed Frame',
    ];

    public function run(): void
    {
        $admin = User::where('role', 'admin')->orderBy('id')->first();
        // Finish labels describe the pictured appearance, not verified varnish specifications.
        $products = [
            ['Balangay Classic Sala Set', 'Sala Set', 'Classic', 'Narra Look', 'A wooden sofa, two armchairs, and a rectangular coffee table with a lower shelf. Horizontal slat backs and broad arms give this set a classic profile.', ['horizontal slats', 'solid seats']],
            ['Hiraya Slatted Sala Set', 'Sala Set', 'Classic', 'Narra Look', 'A wooden sofa, two armchairs, and a matching coffee table with a lower shelf. Slatted seats, ladder-style backs, and straight arms create a coordinated design.', ['slatted seats', 'ladder back']],
            ['Dalisay Cream Cushion Sala Set', 'Sala Set', 'Modern', 'Mahogany', 'A coordinated living-room set with cream cushions, a three-seat sofa, loveseat, armchair, coffee table with drawer, and side table. Rounded wooden arms frame the upholstered seating.', ['cream cushions', 'rounded arms', 'drawer', 'side table']],
            ['Royal Swan Curving Sala Set', 'Sala Set', 'Traditional', 'Narra Look', 'An ornate wooden sofa, four armchairs, and a coffee table with carved swan-inspired backs, decorative crests, and turned legs.', ['swan', 'curving', 'carved', 'turned legs']],
            ['Rosas Pattern Cushion Sala Set', 'Sala Set', 'Classic', 'Mahogany', 'A wooden sofa, four armchairs, coffee table, and two stools. Floral-pattern seat cushions complement horizontal slat backs and rounded arms.', ['floral cushions', 'slatted back', 'stools']],
            ['Sinag Yellow Cushion Sala Set', 'Sala Set', 'Classic', 'Narra Look', 'A wooden sofa, four armchairs, and a matching coffee table with a lower shelf. Yellow seat cushions, shaped top rails, and curved arms define the design.', ['yellow cushions', 'curved arms', 'shaped top rails']],
            ['Dapit Dark Slatted Sala Set', 'Sala Set', 'Classic', 'Chocolate Brown', 'A dark wooden sofa, two armchairs, and a matching coffee table with a lower shelf. Horizontal slat backs and slatted seats keep the design simple and coordinated.', ['dark', 'horizontal slats', 'slatted seats']],
            ['Silong Vertical Slat Sala Set', 'Sala Set', 'Modern', 'Natural', 'A light-toned wooden sofa, two armchairs, and coffee table with a lower shelf. Vertical slats and straight arms give the set an open, clean-lined appearance.', ['vertical slats', 'light tone', 'straight arms']],
            ['Hapon Gray Cushion Sala Set', 'Sala Set', 'Modern', 'Chocolate Brown', 'A dark wooden sofa, two armchairs, and a square coffee table with a lower shelf. Gray seat cushions contrast with the straight arms and horizontal slat backs.', ['gray cushions', 'dark', 'square table']],
            ['Royal Flora Curving Dining Set', 'Dining Set', 'Traditional', 'Narra Look', 'A six-seat dining set with a rounded rectangular tabletop, carved floral chair backs, decorative table edging, and curved legs.', ['six seat', 'floral', 'curving', 'carved']],
            ['Linyang Cream Inlay Dining Set', 'Dining Set', 'Modern', 'Mahogany', 'A six-seat dining set with a rectangular glass-topped table and tall straight-backed chairs. Cream stripes repeat across the tabletop and chair backs.', ['six seat', 'cream inlay', 'striped', 'glass top']],
            ['Sampaguita Floral Curving Bed Frame', 'Curving Bed', 'Traditional', 'Narra Look', 'A wooden bed frame with a prominent floral medallion, diamond-pattern headboard detailing, carved footboard, and turned posts. Mattress size is not supplied; confirm compatibility with the shop.', ['bed', 'floral medallion', 'curving', 'diamond pattern']],
        ];

        DB::transaction(function () use ($products, $admin): void {
            foreach ($products as [$name, $category, $style, $finish, $description, $tags]) {
                $slug = Str::slug($name);
                $image = 'catalog-'.$slug.'.png';
                if (! is_file(public_path('images/catalog/'.$image))) {
                    throw new \RuntimeException('Missing catalog image: '.$image);
                }

                // Estimate the largest seating piece/table/frame, never the entire set footprint.
                [$width, $depth, $height] = match ($category) {
                    'Sala Set' => [225, 90, 92],
                    'Dining Set' => [180, 90, 76],
                    default => [150, 200, 110],
                };
                $product = FurnitureProduct::updateOrCreate(['slug' => $slug], [
                    'name' => $name,
                    'category' => $category,
                    'description' => $description.' Finish classification is based on the pictured color only. Wood species and actual finish must be confirmed with Lance Furniture Shop. Dimensions are standard planning estimates for the main sofa, dining table, or bed frame, not measured dimensions or the footprint of the whole set.',
                    'wood_type' => 'To be confirmed',
                    'varnish_finish' => $finish,
                    'style' => $style,
                    'price' => 1000,
                    'width_cm' => $width,
                    'depth_cm' => $depth,
                    'height_cm' => $height,
                    'image_path' => 'catalog:'.$image,
                    'tags' => $tags,
                    'status' => 'active',
                    'created_by' => $admin?->id,
                ]);
                // The model fills standard sizes; explicitly retain their estimated status.
                $product->newQuery()->whereKey($product->id)->update(['dimensions_estimated' => true]);
            }
        });
    }
}
