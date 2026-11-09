<?php

namespace App\Console\Commands;

use App\Models\ContentConnection;
use App\Models\ContentDelivery;
use App\Services\Content\ContentPublisher;
use App\Services\Content\ContentStorage;
use Illuminate\Console\Command;

class PublishContent extends Command
{
    protected $signature = 'content:publish-due';

    protected $description = 'Voer goedgekeurde contentplanning uit en controleer platformresultaten';

    public function handle(ContentPublisher $publisher): int
    {
        if (! app()->environment('production')) {
            $this->info('Publicatie staat uit in deze omgeving.');

            return self::SUCCESS;
        }
        if (! app(ContentStorage::class)->ready()) {
            $this->error('Content storage is not ready; no publication started.');

            return self::FAILURE;
        }
        ContentConnection::query()->update(['scheduler_seen_at' => now()]);
        ContentDelivery::whereIn('status', ['scheduled', 'processing', 'submitting', 'unknown'])
            ->where('scheduled_at', '<=', now())->orderBy('checked_at')->orderBy('scheduled_at')->limit(50)->get()
            ->each(fn ($d) => $publisher->run($d->id));
        $this->info('Contentplanning gecontroleerd.');

        return self::SUCCESS;
    }
}
