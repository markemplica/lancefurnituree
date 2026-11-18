<?php

namespace Database\Seeders;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalaCollectionSeeder extends Seeder
{
    public const PRODUCTS = [
        ['t01', 'Royal Sunflower Gold Accent Sala Set', 'Traditional', 'Narra Look / Gold', 'An ornate wooden seating set with shell crests, diamond-pattern backs, gold-tone floral accents, and matching carved tables.'],
        ['t02', 'Alon Two-Tone Panel Sala Set', 'Modern', 'Mahogany / Narra Look', 'A two-tone wooden seating set with curved cutout panels, slatted seats, straight arms, and coordinated square tables.'],
        ['t03', 'Habi Basketweave Sala Set', 'Classic', 'Narra Look', 'A wooden sofa, two armchairs, and a square table featuring contrasting light basketweave-pattern panels and curved arms.'],
        ['t04', 'Tindig Slatted Storage Sala Set', 'Modern', 'Mahogany', 'A straight-lined wooden sofa and armchairs with slatted backs, a coffee table with a storage drawer, and a matching side table.'],
        ['t05', 'Balwarte Two-Tone Arch Sala Set', 'Classic', 'Narra / Chocolate Brown', 'A two-tone wooden seating set with dark arched slat panels, curved arms, and a matching rectangular coffee table.'],
        ['t06', 'Pahinga Cream Sectional Sala Set', 'Modern', 'Narra Look', 'A cream-cushioned wooden sectional with a chaise extension, matching armchair, and coffee table with a drawer and open shelves.'],
        ['t07', 'Liwanag Cream Slat Sala Set', 'Classic', 'Narra Look', 'A wooden sofa and two armchairs with light vertical back slats, cream seat cushions, curved arms, and a contrasting panel coffee table.'],
        ['t08', 'Silayan Two-Tone Cushion Sala Set', 'Classic', 'Narra Look', 'A two-tone wooden sofa and two armchairs with light vertical slats, cream cushions, curved arms, and a matching panel coffee table.'],
        ['t09', 'Sinta Heart Curving Sala Set', 'Traditional', 'Narra Look', 'A carved wooden seating set with a prominent heart motif, flowing floral scrollwork, rolled arms, and matching carved tables.'],
        ['t10', 'Mosaiko Brick Pattern Sala Set', 'Modern', 'Natural', 'A light wooden sofa and armchairs with contrasting brick-pattern panels, curved arms, and a matching patterned coffee table.'],
        ['t11', 'Imperial Shell Crest Sala Set', 'Traditional', 'Mahogany', 'An ornate wooden seating set with a central shell crest, lattice-pattern backs, floral carving, rolled arms, and matching tables.'],
        ['t12', 'Royal Shell Lattice Sala Set', 'Traditional', 'Mahogany', 'A carved wooden seating set with a central shell motif, diamond lattice panels, floral scrollwork, and matching carved tables.'],
        ['t13', 'Pamana Floral Vase Sala Set', 'Traditional', 'Mahogany', 'A dark reddish-brown carved seating set with a central floral vase motif, patterned backs, rolled arms, and coordinated tables.'],
        ['t14', 'Marilag Carved Storage Sala Set', 'Traditional', 'Narra Look', 'An ornate wooden seating set with shell and floral carving, patterned backs, and a matching coffee table with visible storage drawers.'],
        ['t15', 'Mutya Fan Back Curving Sala Set', 'Traditional', 'Narra Look', 'A carved wooden seating set with fan-shaped back panels, floral crests, rolled arms, and matching tables on curved legs.'],
        ['t16', 'Royal Garden Medallion Sala Set', 'Traditional', 'Narra Look', 'An ornate wooden sofa and armchairs with a central floral medallion, diamond-pattern panels, rolled arms, and a matching carved coffee table.'],
        ['t18', 'Pulang Rosas Carved Sala Set', 'Traditional', 'Mahogany Red', 'A red-toned wooden sofa and armchairs with open floral back carvings, turned supports, and a matching coffee table with a lower shelf.'],
        ['t19', 'Perlas Shell Curving Sala Set', 'Traditional', 'Narra Look', 'A carved wooden seating set with a large central shell motif, diamond-pattern panels, rolled arms, and coordinated carved tables.'],
        ['t20', 'Ulap Gray Sectional Sala Set', 'Modern', 'Mahogany', 'A gray-cushioned L-shaped wooden sectional with broad paneled arms and a matching coffee table with open storage shelves.'],
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

                $sectional = in_array($source, ['t06', 't20'], true);
                $product = FurnitureProduct::updateOrCreate(['slug' => $slug], [
                    'name' => $name,
                    'category' => 'Sala Set',
                    'description' => $description.' Finish classification describes the pictured appearance only. Wood species, actual varnish, and included pieces must be confirmed with Lance Furniture Shop. Displayed dimensions are standard planning estimates for the main sofa, not measured dimensions or the footprint of the entire set. Confirm the full layout and clearance with the shop.',
                    'wood_type' => 'To be confirmed',
                    'varnish_finish' => $finish,
                    'style' => $style,
                    'price' => 1000,
                    'width_cm' => $sectional ? 280 : 225,
                    'depth_cm' => $sectional ? 180 : 90,
                    'height_cm' => 92,
                    'image_path' => 'catalog:'.$image,
                    'tags' => array_values(array_filter(['sala set', 'living room', strtolower($style), $sectional ? 'sectional' : null, $sectional ? 'l-shaped' : null])),
                    'status' => 'active',
                    'created_by' => $admin?->id,
                ]);
                $product->newQuery()->whereKey($product->id)->update(['dimensions_estimated' => true]);
            }
        });
    }
}
