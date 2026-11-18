<?php

namespace Database\Seeders;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiningCollectionSeeder extends Seeder
{
    public const PRODUCTS = [
        ['d001', 'Yakap Cream Cushion Dining Set', 'Modern', 'Narra Look', 'A rectangular wooden table with a pedestal base and cream upholstered wraparound dining chairs.'],
        ['d002', 'Guhit Gold Inlay Dining Set', 'Modern', 'Chocolate Brown', 'A glass-topped rectangular table with contrasting golden stripes, tall panel-backed chairs, and light upholstered seats.'],
        ['d003', 'Hagdan Classic Dining Set', 'Classic', 'Narra Look', 'A glass-topped wooden table with ladder-back chairs and light upholstered seats.'],
        ['d004', 'Arko Curved Back Dining Set', 'Modern', 'Narra Look', 'A rectangular glass-topped table paired with curved wraparound wooden chair backs and solid wooden seats.'],
        ['d005', 'Arko Dark Glass Dining Set', 'Modern', 'Chocolate Brown', 'A dark rectangular glass-topped table with curved wraparound chair backs and solid wooden seats.'],
        ['d006', 'Haligi Black Slat Dining Set', 'Modern', 'Chocolate Brown', 'A dark glass-topped table with tall wooden chair frames and contrasting black vertical back slats.'],
        ['d007', 'Sinag Vertical Slat Dining Set', 'Classic', 'Narra Look', 'A glass-topped rectangular table paired with tall wooden chairs featuring open vertical slat backs.'],
        ['d008', 'Guhit Amber and Black Dining Set', 'Modern', 'Narra Look / Black', 'An amber-toned glass-topped table with black accent stripes and tall chairs with black vertical back slats.'],
        ['d009', 'Hagdan Black Accent Dining Set', 'Modern', 'Narra Look / Black', 'An amber-toned glass-topped table with black stripes and ladder-back chairs with contrasting black horizontal slats.'],
        ['d010', 'Tahanan Rounded Edge Dining Set', 'Classic', 'Mahogany', 'A glossy reddish-brown table with rounded corners and tall matching wooden ladder-back chairs.'],
        ['d011', 'Haligi Dark Accent Dining Set', 'Modern', 'Mahogany', 'A reddish-brown rectangular wooden table paired with tall chairs featuring black vertical back accents.'],
        ['d012', 'Dalisay White Two-Tone Dining Set', 'Modern', 'White / Chocolate Brown', 'A white and dark-brown dining set with a glass-topped striped table and tall contrasting panel-back chairs.'],
        ['d013', 'Hiraya Ladder Back Dining Set', 'Classic', 'Narra Look', 'A warm amber rectangular table and tall wooden ladder-back chairs, with grooved details on the table legs.'],
        ['d014', 'Hiraya Classic Dining Set', 'Classic', 'Narra Look', 'A warm amber rectangular table with straight legs, grooved accents, and matching tall wooden ladder-back chairs.'],
        ['d015', 'Piging Gloss Dining Set', 'Classic', 'Mahogany', 'A glossy deep reddish-brown rectangular table with rounded corners and matching tall wooden ladder-back chairs.'],
        ['d016', 'Salo Rounded Corner Dining Set', 'Classic', 'Mahogany', 'A glossy deep reddish-brown table with rounded corners, grooved leg details, and tall wooden ladder-back chairs.'],
        ['d017', 'Silahis Gold Panel Dining Set', 'Modern', 'Chocolate Brown', 'A glossy dark wooden table with rounded corners and tall chairs featuring contrasting golden center panels.'],
        ['d018', 'Dapit Black and Amber Dining Set', 'Modern', 'Narra Look / Black', 'A glass-topped table with black stripes and black chair frames surrounding amber vertical back slats.'],
    ];

    public function run(): void
    {
        $admin = User::where('role', 'admin')->orderBy('id')->first();
        DB::transaction(function () use ($admin): void {
            foreach (self::PRODUCTS as [$source, $name, $style, $finish, $description]) {
                $slug = Str::slug($name);
                $image = 'catalog-'.$slug.'.png';
                if (! is_file(public_path('images/catalog/'.$image))) {
                    throw new \RuntimeException('Missing catalog image: '.$image);
                }

                $product = FurnitureProduct::updateOrCreate(['slug' => $slug], [
                    'name' => $name,
                    'category' => 'Dining Set',
                    'description' => $description.' Finish classification describes the pictured appearance only. Wood species, actual varnish, chair count, and included pieces must be confirmed with Lance Furniture Shop. Displayed dimensions are standard planning estimates for the table, not measured dimensions or the space occupied by the complete set and chairs.',
                    'wood_type' => 'To be confirmed',
                    'varnish_finish' => $finish,
                    'style' => $style,
                    'price' => 1000,
                    'width_cm' => 180,
                    'depth_cm' => 90,
                    'height_cm' => 76,
                    'image_path' => 'catalog:'.$image,
                    'tags' => ['dining', 'dining set', strtolower($style)],
                    'status' => 'active',
                    'created_by' => $admin?->id,
                ]);
                $product->newQuery()->whereKey($product->id)->update(['dimensions_estimated' => true]);
            }
        });
    }
}
