<?php

namespace App\Services\Notifications;

use RuntimeException;

class MicrosoftMailException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct(match ($reason) {
            'runtime' => 'Verzenden is alleen beschikbaar op de live-omgeving met een beveiligde websiteverbinding.',
            'scope' => 'Microsoft geeft niet de verwachte beperkte verzendrechten of het gekozen gebruikersaccount terug. Controleer de aparte app-registratie.',
            'authorization' => 'De Microsoft-aanmelding is niet geldig. Verbind opnieuw; de Trunkrs-koppeling blijft behouden.',
            'send_as' => 'Microsoft weigert verzenden als dit postvak. Controleer de machtiging Verzenden als voor het bestaande gebruikersaccount.',
            'busy' => 'De verzendkoppeling is bezig. Probeer later opnieuw.',
            default => 'De aanvraag kon niet worden bevestigd. Controleer de bestemming voordat je opnieuw een proefmail verstuurt.',
        });
    }
}
