<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\OwnerAccess;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function actingAsUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        if ($user->rol === 'admin') {
            DB::table('pitboard_owner_security')->where('id', 1)->update(['user_id' => $user->id, 'secret' => encrypt('test-only')]);
            $owner = OwnerAccess::record();
            $this->withSession(['owner_proof' => ['stamp' => OwnerAccess::stamp($user, $owner), 'until' => now()->addDays(30)->timestamp]]);
        }
        $this->withSession([
            'userId' => $user->id,
            'rol' => $user->rol,
        ]);

        return $user;
    }
}
