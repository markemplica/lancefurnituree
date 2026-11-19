<?php

use App\Models\FurnitureProduct;
use App\Models\RoomImage;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\RecommendationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$customer = User::create([
    'name' => 'Browser Test Customer',
    'email' => 'browser-customer@example.test',
    'password' => Hash::make('BrowserTest-12345'),
    'email_verified_at' => now(),
    'is_active' => true,
    'role' => 'customer',
]);
$product = FurnitureProduct::create([
    'name' => 'Browser Test Dining Table',
    'slug' => 'browser-test-dining-table',
    'category' => 'Dining Table',
    'description' => 'A test catalog table for isolated browser checks.',
    'wood_type' => 'Gmelina',
    'varnish_finish' => 'Natural',
    'style' => 'Modern',
    'price' => 22000,
    'width_cm' => 140,
    'depth_cm' => 80,
    'height_cm' => 75,
    'status' => 'active',
    'image_path' => 'demo:table.svg',
]);
FurnitureProduct::create([
    'name' => 'Browser Test Door',
    'slug' => 'browser-test-door',
    'category' => 'Door',
    'description' => 'A test catalog door for manual doorway fallback checks.',
    'wood_type' => 'Gmelina',
    'varnish_finish' => 'Natural',
    'style' => 'Traditional',
    'price' => 18000,
    'width_cm' => 90,
    'depth_cm' => 4,
    'height_cm' => 210,
    'status' => 'active',
    'image_path' => 'demo:door.svg',
]);
$preference = $customer->preference()->create([
    'furniture_type' => 'Table',
    'budget_min' => 10000,
    'budget_max' => 30000,
    'style' => 'Modern',
    'wood_type' => 'Gmelina',
    'varnish_finish' => 'Natural',
]);
$uploadFixture = imagecreatetruecolor(800, 600);
$wall = imagecolorallocate($uploadFixture, 218, 207, 190);
$floor = imagecolorallocate($uploadFixture, 155, 116, 82);
$line = imagecolorallocate($uploadFixture, 68, 48, 35);
$door = imagecolorallocate($uploadFixture, 91, 66, 49);
imagefilledrectangle($uploadFixture, 0, 0, 799, 355, $wall);
imagefilledrectangle($uploadFixture, 0, 356, 799, 599, $floor);
imagefilledrectangle($uploadFixture, 90, 85, 250, 355, $door);
imagesetthickness($uploadFixture, 5);
for ($x = 0; $x <= 800; $x += 80) imageline($uploadFixture, $x, 355, 400 + ($x - 400) * 2, 599, $line);
for ($y = 400; $y <= 600; $y += 40) imageline($uploadFixture, 0, $y, 799, $y, $line);
imagepng($uploadFixture, dirname((string) getenv('FURNICRAFT_BROWSER_DB')).'/room-upload.png');
imagedestroy($uploadFixture);
$photoPath = 'rooms/'.$customer->id.'/browser-room.png';
Storage::disk('local')->put($photoPath, file_get_contents(dirname((string) getenv('FURNICRAFT_BROWSER_DB')).'/room-upload.png'));
$room = RoomImage::create([
    'user_id' => $customer->id,
    'image_path' => $photoPath,
    'analysis' => app(GeminiService::class)->roomFallback(null, null, 'Table'),
    'analysis_status' => 'fallback',
    'analyzed_at' => now(),
]);
app(RecommendationService::class)->generate($customer, $preference, $room, 'Table');

foreach (['desktop', 'mobile'] as $device) {
    foreach (['change', 'reset'] as $flow) {
        $securityUser = User::factory()->create(['email' => "security-{$flow}-{$device}@example.test", 'password' => Hash::make('OriginalPassword123')]);
        if ($flow === 'reset') \App\Models\PasswordResetCode::create([
            'email' => $securityUser->email, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(15),
        ]);
    }
}
