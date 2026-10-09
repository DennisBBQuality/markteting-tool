<?php

namespace App\Console\Commands;

use App\Models\ProductImageAsset;
use App\Models\ProductImageMetadata;
use App\Models\ProductImageRequest;
use App\Models\User;
use Illuminate\Console\Command;

class WordPressMediaFixture extends Command
{
    protected $signature = 'pitboard:wordpress-media-fixture';

    protected $description = 'Create a clearly marked synthetic media test in the local test-admin account only.';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Only allowed in the local environment.');

            return self::FAILURE;
        }
        $owner = User::where('email', 'admin@marketing.nl')->where('naam', 'Admin')->where('rol', 'admin')->first();
        if (! $owner) {
            $this->error('The dedicated local test admin is missing; no data created.');

            return self::FAILURE;
        }
        $request = ProductImageRequest::create(['user_id' => $owner->id, 'status' => 'completed',
            'source_path' => 'synthetic-wordpress-test.png', 'prompt' => 'Local synthetic upload fixture; no AI calls.',
            'progress' => 100, 'completed_at' => now(), 'generation_context' => ['product_name' => 'TEST WordPress-koppeling', 'product_type' => 'accessory'],
            'results' => [['filename' => 'wordpress-test.png', 'label' => 'TEST — niet publiceren', 'status' => 'rauw', 'variant' => 1]]]);
        $image = imagecreatetruecolor(640, 480);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));
        imagestring($image, 5, 115, 220, 'PITBOARD TEST - NIET PUBLICEREN', imagecolorallocate($image, 150, 20, 20));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $asset = ProductImageAsset::create(['product_image_request_id' => $request->id, 'filename' => 'wordpress-test.png',
            'mime_type' => 'image/png', 'contents_base64' => base64_encode($bytes), 'version' => 1, 'refinement_status' => 'idle']);
        ProductImageMetadata::create(['product_image_asset_id' => $asset->id, 'image_version' => 1, 'revision' => 1, 'status' => 'completed', 'source' => 'manual',
            'fields' => ['filename' => 'pitboard-test-niet-publiceren.webp', 'alt' => 'Grijs testvlak met de tekst Pitboard test, niet publiceren',
                'title' => 'Pitboard test — niet publiceren', 'caption' => 'Uitsluitend lokale koppelingstest.',
                'description' => 'Kunstmatige testafbeelding zonder product- of klantgegevens. Niet publiceren.']]);
        $this->info('http://127.0.0.1:8000/?image_request='.$request->id);

        return self::SUCCESS;
    }
}
