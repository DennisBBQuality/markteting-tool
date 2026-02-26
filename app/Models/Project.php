<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use HasUuids;

    protected $fillable = [
        'naam', 'beschrijving', 'kleur', 'status', 'prioriteit', 'deadline', 'aangemaakt_door',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aangemaakt_door');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    public function calendarItems(): HasMany
    {
        return $this->hasMany(CalendarItem::class, 'project_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'project_id');
    }

    public function chatChannel(): HasOne
    {
        return $this->hasOne(ChatChannel::class, 'project_id');
    }
}
