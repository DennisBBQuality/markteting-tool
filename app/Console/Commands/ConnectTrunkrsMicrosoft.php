<?php

namespace App\Console\Commands;

use App\Services\Trunkrs\TrunkrsException;
use App\Services\Trunkrs\TrunkrsMicrosoftAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ConnectTrunkrsMicrosoft extends Command
{
    protected $signature = 'trunkrs:connect';

    protected $description = 'Koppel het aparte Microsoft-leesaccount op de server; nooit de persoonlijke mailboxeigenaar.';

    public function handle(TrunkrsMicrosoftAuth $auth): int
    {
        // Serialize token creation/rotation with scheduled syncs.
        $lock = Cache::lock('trunkrs-sync', 1200);
        if (! $lock->get()) {
            $this->error('Een Trunkrs-controle is bezig. Probeer later opnieuw.');

            return self::FAILURE;
        }
        try {
            $this->warn('Meld alleen aan met het aparte leesaccount. De beheerder moet uitsluitend de rapportmap delen, zonder Full Access.');
            if (! $this->confirm('Zijn deze beperkte maprechten door de Microsoft-beheerder gecontroleerd?')) {
                return self::FAILURE;
            }
            $device = $auth->startDeviceLogin();
            if (empty($device['user_code']) || empty($device['device_code'])) {
                throw new TrunkrsException('authorization');
            }
            $this->line('Open https://microsoft.com/devicelogin en vul de tijdelijke code in: '.$device['user_code']);
            $deadline = time() + min(900, (int) ($device['expires_in'] ?? 900));
            $interval = max(5, (int) ($device['interval'] ?? 5));
            while (time() < $deadline) {
                sleep($interval);
                $result = $auth->finishDeviceLogin($device['device_code']);
                if ($result === 'connected') {
                    $this->info('Leesaccount gekoppeld; token is versleuteld bewaard. Automatische verwerking vereist nog de serverplanning.');

                    return self::SUCCESS;
                }
                if ($result === 'slow_down') {
                    $interval += 5;
                }
            }
            throw new TrunkrsException('authorization');
        } catch (TrunkrsException $e) {
            $this->error(TrunkrsException::description($e->reason));

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
