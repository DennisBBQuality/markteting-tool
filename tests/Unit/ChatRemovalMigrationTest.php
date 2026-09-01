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
        $migration = require database_path('migrations/2026_09_01_000001_remove_chat_tables.php');

        foreach (['chat_channels', 'chat_threads', 'chat_messages', 'notifications'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        try {
            $migration->down();

            foreach (['chat_channels', 'chat_threads', 'chat_messages', 'notifications'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
            }
        } finally {
            $migration->up();
        }

        foreach (['chat_channels', 'chat_threads', 'chat_messages', 'notifications'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }
}
