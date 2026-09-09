<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            'Burger' => 'Burgers',
            'Karbonade' => 'Karbonades',
            'Speklap' => 'Speklappen',
            'Drumstick' => 'Drumsticks',
            'Kippenvleugel' => 'Kippenvleugels',
            'Kip spies' => 'Kip spiezen',
            'Kalfswang' => 'Kalfswang gevliesd',
        ];
        foreach ($renames as $old => $new) {
            DB::table('product_dossier_options')->where('type', 'cut')->where('label', $old)->update([
                'label' => $new,
                'updated_at' => now(),
            ]);
        }

        DB::table('product_dossier_options')->updateOrInsert(
            ['type' => 'cut', 'label' => 'Kip kant & klaar'],
            ['position' => 47, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('product_dossier_options')->where('type', 'selection')->where('label', 'Wagyu')->update([
            'label' => 'Wagyu vlees',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('product_dossier_options')->where('type', 'cut')->where('label', 'Kip kant & klaar')->delete();
        DB::table('product_dossier_options')->where('type', 'selection')->where('label', 'Wagyu vlees')->update(['label' => 'Wagyu']);

        $renames = [
            'Burgers' => 'Burger',
            'Karbonades' => 'Karbonade',
            'Speklappen' => 'Speklap',
            'Drumsticks' => 'Drumstick',
            'Kippenvleugels' => 'Kippenvleugel',
            'Kip spiezen' => 'Kip spies',
            'Kalfswang gevliesd' => 'Kalfswang',
        ];
        foreach ($renames as $new => $old) {
            DB::table('product_dossier_options')->where('type', 'cut')->where('label', $new)->update(['label' => $old]);
        }
    }
};
