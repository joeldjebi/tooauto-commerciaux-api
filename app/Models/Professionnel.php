<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Professionnel extends Model
{
    use HasFactory;
    protected $table = 'professionnels';
    protected $fillable = [
        'nom',
        'prenoms',
        'role',
        'mobile',
        'password',
    ];

    public function etablissements()
    {
        return $this->hasMany(Etablissement::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }

    public function abonnement_pros()
    {
        return $this->hasMany(Abonnement_pro::class);
    }

    public function forfaits_pros()
    {
        return $this->hasMany(Forfait_pro::class);
    }
}