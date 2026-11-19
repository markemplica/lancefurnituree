<?php

namespace Tests\Feature;

use App\Services\{GeminiRoomVisionService, RoomPhotoService, RoomPreviewRasterService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomImageOrientationTest extends TestCase
{
    public function test_legacy_raster_provider_input_and_new_upload_share_all_eight_exif_orientations(): void
    {
        Storage::fake('local');
        $colors = [0xff0000, 0x00ff00, 0x0000ff, 0xffff00];
        $orders = [[0,1,2,3],[1,0,3,2],[2,3,0,1],[3,2,1,0],[0,3,2,1],[3,0,1,2],[2,1,0,3],[1,2,3,0]];
        $image = imagecreatetruecolor(800,600);
        foreach ([[0,0,399,299],[400,0,799,299],[400,300,799,599],[0,300,399,599]] as $i => $rect) imagefilledrectangle($image, ...array_merge($rect, [$colors[$i]]));
        ob_start(); imagejpeg($image, null, 95); $jpeg = ob_get_clean(); imagedestroy($image);
        foreach ($orders as $index => $order) {
            $orientation = $index + 1;
            $exif = "Exif\0\0II".pack('vVv',42,8,1).pack('vvVv',0x0112,3,1,$orientation)."\0\0".pack('V',0);
            $bytes = substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2);
            $normalized = app(RoomPreviewRasterService::class)->normalize($bytes)['bytes'];
            [$prepared] = (new \ReflectionMethod(GeminiRoomVisionService::class,'prepareAnalysisImage'))->invoke(app(GeminiRoomVisionService::class), $bytes, 'image/jpeg');
            $path = app(RoomPhotoService::class)->storeUpload(UploadedFile::fake()->createWithContent('orientation.jpg',$bytes), 1);
            foreach (['legacy raster'=>$normalized,'provider input'=>$prepared,'new upload'=>Storage::disk('local')->get($path)] as $source => $result) {
                $decoded = imagecreatefromstring($result); $w=imagesx($decoded); $h=imagesy($decoded);
                $this->assertSame($orientation>=5 ? [600,800] : [800,600], [$w,$h], "$source orientation $orientation dimensions");
                foreach ([[15,15],[$w-16,15],[$w-16,$h-16],[15,$h-16]] as $i => [$x,$y]) {
                    $actual=imagecolorat($decoded,$x,$y); $expected=$colors[$order[$i]];
                    foreach ([16,8,0] as $shift) $this->assertEqualsWithDelta(($expected>>$shift)&255,($actual>>$shift)&255,12,"$source orientation $orientation corner $i");
                }
                imagedestroy($decoded);
            }
        }
    }
}
