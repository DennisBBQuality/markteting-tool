<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $publicDisk = Storage::disk('public');
        $localDisk = Storage::disk('local');

        if ($publicDisk->exists('uploads')) {
            $files = $publicDisk->allFiles('uploads');

            foreach ($files as $file) {
                // Laat beide bestanden staan bij een naamconflict. Automatisch
                // overschrijven of verwijderen kan anders stil dataverlies geven.
                if ($localDisk->exists($file)) {
                    continue;
                }

                $source = $publicDisk->readStream($file);
                if (!is_resource($source)) {
                    continue;
                }

                try {
                    $copied = $localDisk->writeStream($file, $source);
                } finally {
                    fclose($source);
                }

                // Verwijder de openbare bron uitsluitend na een aantoonbaar
                // geslaagde kopie. Storage-disks kunnen fouten als false melden.
                if ($copied && $localDisk->exists($file)) {
                    $publicDisk->delete($file);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Een rollback mag beveiligde uploads niet opnieuw openbaar maken.
        // De bestanden blijven daarom bewust op de private lokale disk staan.
    }
};
