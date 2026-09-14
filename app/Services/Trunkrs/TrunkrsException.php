<?php

namespace App\Services\Trunkrs;

use RuntimeException;

class TrunkrsException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $retryAfter = 600)
    {
        // Only fixed, non-sensitive codes may reach command output or logs.
        parent::__construct($reason);
    }

    public static function description(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'configuration' => 'De Microsoft-koppeling moet nog door de beheerder worden ingesteld.',
            'authorization' => 'Microsoft-toegang ontbreekt of is verlopen. Laat de beheerder opnieuw verbinden.',
            'scope' => 'Het Microsoft-account of de verleende rechten komen niet overeen met de afgesproken leestoegang.',
            'rate_limit' => 'Microsoft vraagt om even te wachten. De server probeert het later opnieuw.',
            'network', 'provider' => 'Microsoft is tijdelijk niet bereikbaar. Het laatste ingelezen rapport blijft bewaard.',
            'invalid_report' => 'Het nieuwe rapport heeft een onbekend of ongeldig formaat. Het vorige rapport blijft bewaard.',
            'missing_attachment' => 'De rapportmail bevat geen herkenbare ZIP- of CSV-bijlage.',
            'page_limit' => 'Niet alle rapportmails konden worden gecontroleerd. De beheerder moet de inleeslimiet controleren.',
            default => 'Het inlezen is niet afgerond. Laat de beheerder de servercontrole nakijken.',
        };
    }
}
