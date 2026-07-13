<?php

namespace App\Models\CustomerService;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasUuids;

    protected $table = 'cs_tickets';

    protected $fillable = [
        'ticketnummer',
        'onderwerp',
        'klant_naam',
        'klant_email',
        'status',
        'prioriteit',
        'behandelaar_id',
        'aangemaakt_door',
        'versie',
        'laatste_bericht_op',
        'afgehandeld_op',
    ];

    protected function casts(): array
    {
        return [
            'laatste_bericht_op' => 'datetime',
            'afgehandeld_op' => 'datetime',
            'versie' => 'integer',
        ];
    }

    public function behandelaar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'behandelaar_id');
    }

    public function aangemaaktDoor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aangemaakt_door');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(TicketNote::class)->orderBy('created_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class)->orderByDesc('created_at');
    }
}
