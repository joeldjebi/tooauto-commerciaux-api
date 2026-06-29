<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicule extends Model
{
    use HasFactory;

    protected $fillable = [
        'matricule',
        'marque_id',
        'modele_id',
        'type_vehicule_id',
        'user_id',
        'station_id',
        'station_service_id',
        'annee',
        'couleur',
        'kilometrage',
        'statut'
    ];

    protected $casts = [
        'photos' => 'array',
    ];

    public function marque()
    {
        return $this->belongsTo(Marque::class);
    }

    public function modele()
    {
        return $this->belongsTo(Modele::class);
    }

    public function typeVehicule()
    {
        return $this->belongsTo(TypeVehicule::class, 'type_vehicule_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}