<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CacheMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_tables_have_the_columns_required_by_laravel(): void
    {
        $this->assertTrue(Schema::hasColumns('cache', [
            'key',
            'value',
            'expiration',
        ]));

        $this->assertTrue(Schema::hasColumns('cache_locks', [
            'key',
            'owner',
            'expiration',
        ]));
    }
}
