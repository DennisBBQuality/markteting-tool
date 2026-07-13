<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function actingAsUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        $this->withSession([
            'userId' => $user->id,
            'rol' => $user->rol,
        ]);

        return $user;
    }
}
