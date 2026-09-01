<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatRemovalMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_tables_are_removed_and_can_be_restored_by_rollback(): void
    {
        foreach (['chat_channels', 'chat_threads', 'chat_messages', 'notifications'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        try {
            $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

            foreach (['chat_channels', 'chat_threads', 'chat_messages', 'notifications'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
            }
        } finally {
            $this->artisan('migrate')->assertSuccessful();
        }

        foreach (['chat_channels', 'chat_threads', 'chat_messages', 'notifications'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }
}
