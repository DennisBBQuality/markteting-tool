<?php

namespace App\Models\CustomerService;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    use HasUuids;

    protected $table = 'cs_ticket_messages';

    protected $fillable = [
        'ticket_id',
        'richting',
        'kanaal',
        'auteur_id',
        'inhoud',
        'client_message_id',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
