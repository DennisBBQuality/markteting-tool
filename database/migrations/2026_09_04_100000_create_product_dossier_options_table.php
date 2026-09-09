<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_dossier_options', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->index();
            $table->string('label', 160);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['type', 'label']);
        });

        $now = now();
        $options = [
            'category' => [
                'BBQ vlees', 'Dagelijks vlees', 'Boxen en pakketten', 'Rund', 'Varken',
                'Kip en gevogelte', 'Lam', 'Kalf', 'Wild', 'Vis', 'BBQ winkel', 'Pizza',
                'Smaakmakers', 'Borrelsnacks',
            ],
            'cut' => [
                'Bavette / maanvlees', 'Bavette / vinkenlap', 'Beef Wellington', 'Biefstuk',
                'Brisket', 'Burgers', 'Carpaccio', 'Côte de Boeuf', 'Diamanthaas', 'Entrecote',
                'Flat iron', 'Kogelbiefstuk', 'Maminha', 'Ossenhaas', 'Picanha', 'Ribeye',
                'Rollade', 'Rosbief', 'Short ribs', 'Sucade', 'T-bone', 'Tomahawk', 'Tournedos',
                'Beenham', 'Boston butt', 'Buikspek', 'Iberico ribfingers', 'Karbonades',
                'Procureur', 'Ribroast', 'Secreto', 'Spareribs', 'Speenvarken', 'Speklappen',
                'Varkensfilet', 'Varkenshaas', 'Varkensham', 'Varkensnek', 'Varkensrollade',
                'Varkenswang', 'Drumsticks', 'Hele kip', 'Kalkoen', 'Kipdijfilet', 'Kipfilet',
                'Kip kant & klaar', 'Kip kotelet', 'Kippenbout', 'Kippengehakt', 'Kippenvleugels', 'Kip schnitzel',
                'Kip spiezen', 'Kip worst', 'Lamsbout', 'Lamsfilet', 'Lamsgehakt', 'Lamshaas',
                'Lamsnek', 'Lamsrack', 'Lamsschenkel', 'Lamsschouder', 'Lamsshoarma',
                'Lamszwezerik', 'Kalfsentrecote', 'Kalfsgehakt', 'Kalfshaas',
                'Kalfshartzwezerik', 'Kalfsmuis', 'Kalfsossobuco', 'Kalfspicanha', 'Kalfsrack',
                'Kalfsribeye', 'Kalfsspareribs', 'Kalfssucade', 'Kalfswang gevliesd', 'Bizon', 'Eend',
                'Eland', 'Fazant', 'Haas', 'Hert', 'Konijn', 'Krokodil', 'Kwartel', 'Parelhoen',
                'Ree', 'Wildrundvlees', 'Wildzwijn', 'Zebra', 'Calamari', 'Coquilles', 'Dorade',
                'Forel', 'Garnalen', 'Kabeljauw', 'Krab', 'Kreeft', 'Langoustine', 'Makreel',
                'Tonijn', 'Wilde zalm', 'Zalm', 'Zeebaars', 'Zwaardvis',
            ],
            'selection' => [
                'Angus', 'Dry age', 'Duroc', 'Duurzaam', 'El Rancho', 'Halal', 'Heyde Hoeve',
                'Hoeve Hoen', 'Iberico', 'La Finca', 'Tierno', 'Wagyu vlees', 'Wildrundvlees',
            ],
        ];

        $rows = [];
        foreach ($options as $type => $labels) {
            foreach ($labels as $position => $label) {
                $rows[] = compact('type', 'label', 'position') + [
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('product_dossier_options')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_dossier_options');
    }
};
