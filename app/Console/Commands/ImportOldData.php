<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportOldData extends Command
{
    protected $signature = 'import:old-data {path : Path to the old marketing.db file}';
    protected $description = 'Import data from the old Node.js SQLite database';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (!file_exists($path)) {
            $this->error("Database file not found: {$path}");
            return 1;
        }

        $old = new \PDO('sqlite:' . $path);
        $old->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Import users
        $this->info('Importing users...');
        $users = $old->query('SELECT * FROM users')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($users as $u) {
            DB::table('users')->updateOrInsert(
                ['id' => $u['id']],
                [
                    'naam' => $u['naam'],
                    'email' => $u['email'],
                    'wachtwoord_hash' => $u['wachtwoord_hash'],
                    'rol' => $u['rol'],
                    'kleur' => $u['kleur'] ?? '#3B82F6',
                    'avatar' => $u['avatar'] ?? null,
                    'actief' => (bool) ($u['actief'] ?? 1),
                    'created_at' => $u['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->info("  Imported " . count($users) . " users");

        // Import projects
        $this->info('Importing projects...');
        $projects = $old->query('SELECT * FROM projects')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($projects as $p) {
            DB::table('projects')->updateOrInsert(
                ['id' => $p['id']],
                [
                    'naam' => $p['naam'],
                    'beschrijving' => $p['beschrijving'] ?? '',
                    'kleur' => $p['kleur'] ?? '#3B82F6',
                    'status' => $p['status'] ?? 'actief',
                    'prioriteit' => $p['prioriteit'] ?? 'normaal',
                    'deadline' => $p['deadline'] ?? null,
                    'aangemaakt_door' => $p['aangemaakt_door'] ?? null,
                    'created_at' => $p['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->info("  Imported " . count($projects) . " projects");

        // Import tasks
        $this->info('Importing tasks...');
        $tasks = $old->query('SELECT * FROM tasks')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($tasks as $t) {
            DB::table('tasks')->updateOrInsert(
                ['id' => $t['id']],
                [
                    'project_id' => $t['project_id'] ?? null,
                    'titel' => $t['titel'],
                    'beschrijving' => $t['beschrijving'] ?? '',
                    'status' => $t['status'] ?? 'todo',
                    'prioriteit' => $t['prioriteit'] ?? 'normaal',
                    'toegewezen_aan' => $t['toegewezen_aan'] ?? null,
                    'deadline' => $t['deadline'] ?? null,
                    'kleur' => $t['kleur'] ?? null,
                    'link' => $t['link'] ?? null,
                    'positie' => $t['positie'] ?? 0,
                    'created_at' => $t['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->info("  Imported " . count($tasks) . " tasks");

        // Import calendar items
        $this->info('Importing calendar items...');
        $items = $old->query('SELECT * FROM calendar_items')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($items as $c) {
            DB::table('calendar_items')->updateOrInsert(
                ['id' => $c['id']],
                [
                    'project_id' => $c['project_id'] ?? null,
                    'titel' => $c['titel'],
                    'beschrijving' => $c['beschrijving'] ?? '',
                    'type' => $c['type'] ?? 'content',
                    'datum_start' => $c['datum_start'],
                    'datum_eind' => $c['datum_eind'] ?? null,
                    'kleur' => $c['kleur'] ?? null,
                    'link' => $c['link'] ?? null,
                    'aangemaakt_door' => $c['aangemaakt_door'] ?? null,
                    'created_at' => $c['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->info("  Imported " . count($items) . " calendar items");

        // Import notes
        $this->info('Importing notes...');
        $notes = $old->query('SELECT * FROM notes')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($notes as $n) {
            DB::table('notes')->updateOrInsert(
                ['id' => $n['id']],
                [
                    'project_id' => $n['project_id'] ?? null,
                    'task_id' => $n['task_id'] ?? null,
                    'titel' => $n['titel'],
                    'inhoud' => $n['inhoud'] ?? '',
                    'kleur' => $n['kleur'] ?? '#FEF3C7',
                    'link' => $n['link'] ?? null,
                    'aangemaakt_door' => $n['aangemaakt_door'] ?? null,
                    'created_at' => $n['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->info("  Imported " . count($notes) . " notes");

        // Import attachments
        $this->info('Importing attachments...');
        $attachments = $old->query('SELECT * FROM attachments')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($attachments as $a) {
            DB::table('attachments')->updateOrInsert(
                ['id' => $a['id']],
                [
                    'project_id' => $a['project_id'] ?? null,
                    'task_id' => $a['task_id'] ?? null,
                    'calendar_item_id' => $a['calendar_item_id'] ?? null,
                    'note_id' => $a['note_id'] ?? null,
                    'bestandsnaam' => $a['bestandsnaam'],
                    'originele_naam' => $a['originele_naam'],
                    'mimetype' => $a['mimetype'] ?? null,
                    'grootte' => $a['grootte'] ?? null,
                    'geupload_door' => $a['geupload_door'] ?? null,
                    'created_at' => $a['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->info("  Imported " . count($attachments) . " attachments");

        // Import chat channels
        $this->info('Importing chat channels...');
        $channels = $old->query('SELECT * FROM chat_channels')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($channels as $ch) {
            DB::table('chat_channels')->updateOrInsert(
                ['id' => $ch['id']],
                [
                    'naam' => $ch['naam'],
                    'type' => $ch['type'] ?? 'general',
                    'project_id' => $ch['project_id'] ?? null,
                    'beschrijving' => $ch['beschrijving'] ?? '',
                    'aangemaakt_door' => $ch['aangemaakt_door'] ?? null,
                    'created_at' => $ch['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->info("  Imported " . count($channels) . " chat channels");

        // Import chat threads
        $this->info('Importing chat threads...');
        $threads = $old->query('SELECT * FROM chat_threads')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($threads as $th) {
            DB::table('chat_threads')->updateOrInsert(
                ['id' => $th['id']],
                [
                    'user1_id' => $th['user1_id'],
                    'user2_id' => $th['user2_id'],
                    'created_at' => $th['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->info("  Imported " . count($threads) . " chat threads");

        // Import chat messages
        $this->info('Importing chat messages...');
        $messages = $old->query('SELECT * FROM chat_messages')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($messages as $m) {
            DB::table('chat_messages')->updateOrInsert(
                ['id' => $m['id']],
                [
                    'channel_id' => $m['channel_id'] ?? null,
                    'thread_id' => $m['thread_id'] ?? null,
                    'afzender_id' => $m['afzender_id'],
                    'inhoud' => $m['inhoud'],
                    'created_at' => $m['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->info("  Imported " . count($messages) . " chat messages");

        // Import notifications
        $this->info('Importing notifications...');
        $notifications = $old->query('SELECT * FROM notifications')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($notifications as $n) {
            DB::table('notifications')->updateOrInsert(
                ['id' => $n['id']],
                [
                    'user_id' => $n['user_id'],
                    'type' => $n['type'] ?? 'mention',
                    'message_id' => $n['message_id'] ?? null,
                    'channel_id' => $n['channel_id'] ?? null,
                    'thread_id' => $n['thread_id'] ?? null,
                    'gelezen' => (bool) ($n['gelezen'] ?? 0),
                    'created_at' => $n['created_at'] ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->info("  Imported " . count($notifications) . " notifications");

        $this->info('');
        $this->info('Import completed successfully!');
        return 0;
    }
}
