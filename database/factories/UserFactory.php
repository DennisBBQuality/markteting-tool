<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current randomly generated password hash used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'naam' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'wachtwoord_hash' => static::$password ??= Hash::make(Str::random(40)),
            'rol' => 'lid',
            'kleur' => fake()->hexColor(),
            'actief' => true,
        ];
    }
}
