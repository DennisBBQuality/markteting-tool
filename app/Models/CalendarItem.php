<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarItem extends Model
{
    use HasUuids;

    protected $table = 'calendar_items';

    protected $fillable = [
        'project_id', 'titel', 'beschrijving', 'type', 'datum_start',
        'datum_eind', 'kleur', 'link', 'aangemaakt_door',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aangemaakt_door');
    }
}
