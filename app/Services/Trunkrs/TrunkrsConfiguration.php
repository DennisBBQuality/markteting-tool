<?php

namespace App\Services\Trunkrs;

use App\Models\TrunkrsSetting;
use Illuminate\Support\Facades\Schema;

class TrunkrsConfiguration
{
    public const FIELDS = ['tenant_id', 'client_id', 'reader_user_id', 'mailbox', 'folder_id'];

    private array $stored;

    public function __construct()
    {
        // A rollout may serve the previous schema until the additive migration runs.
        $this->stored = Schema::hasTable('trunkrs_settings') ? (TrunkrsSetting::find(1)?->payload ?? []) : [];
    }

    public function get(string $key): mixed
    {
        return array_key_exists($key, $this->stored) ? $this->stored[$key] : config('trunkrs.'.$key);
    }
}
