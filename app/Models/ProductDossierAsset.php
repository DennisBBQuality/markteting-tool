<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductDossierAsset extends Model
{
    use HasUuids;

    protected $fillable = ['product_dossier_id', 'mime_type', 'contents_base64'];

    protected $hidden = ['contents_base64'];
}
