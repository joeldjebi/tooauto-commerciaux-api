<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Forfait_pro extends Model
{
    use HasFactory;
    protected $table = 'forfait_pros';
    protected $fillable = [
        'nom',
        'description',
        'prix',
        'duree',
    ];

    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }

    public function abonnement_pros()
    {
        return $this->hasMany(Abonnement_pro::class);
    }
}