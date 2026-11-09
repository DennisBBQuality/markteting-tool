<?php

namespace App\Services\Content;

use RuntimeException;

/** Only fixed descriptions and numeric provider codes may reach the setup screen. */
class ConnectionSetupException extends RuntimeException
{
    public function __construct(string $step, ?int $status = null, ?int $providerCode = null)
    {
        $description = match ($step) {
            'meta_exchange' => 'Meta kon de aanmelding niet omzetten in toegang. Controleer het app-ID en appgeheim en start de aanmelding opnieuw.',
            'meta_extend' => 'Meta kon de toegang niet verlengen. Controleer de appinstellingen en start de aanmelding opnieuw.',
            'meta_permissions' => 'De benodigde Meta-rechten konden niet worden bevestigd. Controleer de publicatierechten en meld opnieuw aan.',
            'meta_accounts' => 'De Facebook-pagina en het gekoppelde Instagram-account konden niet worden opgehaald. Controleer de geselecteerde accounts en paginatoegang.',
            'youtube_exchange' => 'Google kon geen blijvende toegang bevestigen. Controleer de appgegevens en meld opnieuw aan.',
            'youtube_accounts' => 'Het YouTube-kanaal kon niet worden opgehaald. Controleer de kanaaltoegang en of YouTube Data API v3 is ingeschakeld.',
            'no_accounts' => 'Geen toegankelijk publicatieaccount gevonden. Controleer de accountselectie en je beheerdersrechten.',
            'too_many_accounts' => 'Te veel Facebook-pagina’s gevonden. Beperk de aanmelding tot de benodigde bedrijfspagina.',
            default => 'De koppeling kon niet worden bevestigd. Controleer de appinstellingen en meld opnieuw aan.',
        };
        // Never include provider messages, request URLs, tokens, or a previous exception.
        $codes = [];
        if ($status !== null && $status >= 100 && $status <= 599) {
            $codes[] = 'HTTP '.$status;
        }
        if ($providerCode !== null && $providerCode >= 0 && $providerCode <= 9999999) {
            $codes[] = 'platformcode '.$providerCode;
        }
        parent::__construct($description.($codes ? ' ('.implode(', ', $codes).')' : '').' Open Pitboard → Content → Kanalen om verder te gaan.');
    }
}
