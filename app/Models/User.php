<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasUuids;

    protected $fillable = [
        'naam', 'email', 'wachtwoord_hash', 'rol', 'kleur', 'avatar', 'actief',
    ];

    protected $hidden = [
        'wachtwoord_hash',
    ];

    protected function casts(): array
    {
        return [
            'actief' => 'boolean',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'aangemaakt_door');
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'taak_gebruiker', 'user_id', 'task_id')
                    ->withTimestamps();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'aangemaakt_door');
    }
}
