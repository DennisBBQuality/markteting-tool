<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Task extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'titel', 'beschrijving', 'status', 'prioriteit',
        'deadline', 'kleur', 'link', 'positie',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function toegewezenen(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'taak_gebruiker', 'task_id', 'user_id')
                    ->withTimestamps();
    }
}
