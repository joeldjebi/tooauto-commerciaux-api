<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Forfait extends Model
{
    use HasFactory;
    protected $table = 'forfait_usagers';
    protected $fillable = [
        'nom',
        'prix',
        'duree',
    ];

    public function abonnement_usagers()
    {
        return $this->hasMany(AbonnementUsager::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }
}