<?php

namespace Database\Seeders;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomizedCurvingDoorsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->orderBy('id')->first();
        $doors = [
            ['Royal Rosette Curving Door', 'Mahogany', 'Mahogany', 'catalog-custom-royal-rosette-door.png', 'a central floral rosette, diamond lattice panels, and ornate floral scrollwork'],
            ['Heritage Scroll Curving Door', 'Gmelina', 'Narra Look', 'catalog-custom-heritage-scroll-door.png', 'flowing leaf scrolls and a circular geometric medallion'],
            ['Floral Vase Curving Door', 'Gmelina', 'Mahogany', 'catalog-custom-floral-vase-door.png', 'a carved floral vase, curved upper panel, and four lower raised panels'],
        ];

        DB::transaction(function () use ($doors, $admin): void {
            foreach ($doors as [$name, $wood, $finish, $image, $design]) {
                if (! is_file(public_path('images/catalog/'.$image))) {
                    throw new \RuntimeException('Missing catalog image: '.$image);
                }

                FurnitureProduct::updateOrCreate(['slug' => Str::slug($name)], [
                    'name' => $name,
                    'category' => 'Customized Door',
                    'description' => "Made-to-order {$wood} full-curving door featuring {$design}, with a {$finish} varnish look. Size options: 80 × 210 cm or 90 × 210 cm (width × height). The displayed 90 cm width is a planning size; thickness is a standard estimate. Confirm the selected size, actual thickness, and final finish with Lance Furniture Shop when submitting your customization request.",
                    'wood_type' => $wood,
                    'varnish_finish' => $finish,
                    'style' => 'Traditional',
                    'price' => 13000,
                    'width_cm' => 90,
                    'depth_cm' => null,
                    'height_cm' => 210,
                    'image_path' => 'catalog:'.$image,
                    'tags' => ['door', 'customized', 'made to order', 'curving', 'full curving', 'carved', '80x210', '90x210'],
                    'status' => 'active',
                    'created_by' => $admin?->id,
                ]);
            }
        });
    }
}
