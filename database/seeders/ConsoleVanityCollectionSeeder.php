<?php

namespace Database\Seeders;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsoleVanityCollectionSeeder extends Seeder
{
    public const NAMES = [
        'Royal Crest Vanity Set',
        'Haligi Classic Console and Mirror Set',
        'Luna Round Mirror Console Set',
        'Imperial Scroll Console and Mirror Set',
        'Royal Blossom Console and Mirror Set',
        'Tahanan Six-Drawer Dresser with Mirror',
        'Reyna Curving Vanity Set',
    ];

    public function run(): void
    {
        $admin = User::where('role', 'admin')->orderBy('id')->first();
        $products = [
            ['Royal Crest Vanity Set', 'Traditional', 'A vanity set with a tall carved crest mirror, two upper glass-front cabinets, lower storage cabinets and drawers, and a matching wooden stool.', ['vanity', 'mirror', 'carved crest', 'glass cabinets', 'stool']],
            ['Haligi Classic Console and Mirror Set', 'Classic', 'A console and rectangular mirror set with grooved mirror uprights, shaped pedestal supports, and a broad lower shelf.', ['console', 'rectangular mirror', 'pedestal', 'lower shelf']],
            ['Luna Round Mirror Console Set', 'Modern', 'A round framed mirror paired with a console table featuring sweeping U-shaped supports and a stepped base.', ['console', 'round mirror', 'curved base', 'u-shaped']],
            ['Imperial Scroll Console and Mirror Set', 'Traditional', 'An ornate rectangular mirror and console set with carved scrolls, floral details, three drawers, curved legs, and a lower shelf.', ['console', 'mirror', 'scrollwork', 'carved', 'three drawers']],
            ['Royal Blossom Console and Mirror Set', 'Traditional', 'A floral crest mirror and console set with open scrollwork, a carved floral apron, curved legs, and a lower shelf.', ['console', 'mirror', 'floral', 'carved', 'curved legs']],
            ['Tahanan Six-Drawer Dresser with Mirror', 'Classic', 'A dresser with six drawers arranged in two columns, metal-tone handles, and a matching rectangular framed mirror.', ['dresser', 'mirror', 'six drawers', 'storage']],
            ['Reyna Curving Vanity Set', 'Traditional', 'A vanity set with a shaped crest mirror, five drawers, carved scroll details, curved legs, and a matching cream upholstered stool.', ['vanity', 'mirror', 'curving', 'five drawers', 'upholstered stool']],
        ];

        DB::transaction(function () use ($products, $admin): void {
            foreach ($products as [$name, $style, $description, $tags]) {
                $slug = Str::slug($name);
                $image = 'catalog-'.$slug.'-updated.png';
                if (! is_file(public_path('images/catalog/'.$image))) {
                    throw new \RuntimeException('Missing catalog image: '.$image);
                }

                $product = FurnitureProduct::updateOrCreate(['slug' => $slug], [
                    'name' => $name,
                    'category' => 'Console',
                    'description' => $description.' The pictured warm amber color is classified as Narra Look for catalog browsing; actual wood species and varnish must be confirmed with Lance Furniture Shop. Displayed dimensions are standard planning estimates for the main furniture unit with mirror, not measured dimensions. Confirm the overall size and included pieces with the shop.',
                    'wood_type' => 'To be confirmed',
                    'varnish_finish' => 'Narra Look',
                    'style' => $style,
                    'price' => 1000,
                    'width_cm' => 120,
                    'depth_cm' => 45,
                    'height_cm' => 180,
                    'image_path' => 'catalog:'.$image,
                    'tags' => $tags,
                    'status' => 'active',
                    'created_by' => $admin?->id,
                ]);
                $product->newQuery()->whereKey($product->id)->update(['dimensions_estimated' => true]);
            }
        });
    }
}
