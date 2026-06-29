<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Prospecter extends Model
{
    use HasFactory;

    // Champs modifiables
    protected $fillable = [
        'nom_etablissement',
        'name_gerant',
        'name_responsable_commercial',
        'mobile',
        'email',
        'type_etablissement_id',
        'adresse',
        'longitude',
        'latitude',
        'commercial_id',
        'agree',
    ];

    // Relations
    public function commercial()
    {
        return $this->belongsTo(Commercial::class);
    }

    public function type_etablissement()
    {
        return $this->belongsTo(Type_etablissement::class, 'type_etablissement_id');
    }
}