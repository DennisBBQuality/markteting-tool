<?php

namespace Database\Seeders;

use App\Models\ChatChannel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Default admin user
        if (User::count() === 0) {
            User::create([
                'naam' => 'Admin',
                'email' => 'admin@marketing.nl',
                'wachtwoord_hash' => Hash::make('admin123'),
                'rol' => 'admin',
                'kleur' => '#3B82F6',
            ]);
        }

        // Default general chat channel
        if (ChatChannel::where('type', 'general')->count() === 0) {
            ChatChannel::create([
                'naam' => 'algemeen',
                'type' => 'general',
                'beschrijving' => 'Algemene teamchat',
            ]);
        }
    }
}
