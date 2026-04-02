<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
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

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'toegewezen_aan');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'aangemaakt_door');
    }
}
