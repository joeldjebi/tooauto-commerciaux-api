<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Abonnement_pro extends Model
{
    use HasFactory;
    protected $table = 'abonnement_pros';
    protected $fillable = [
        'etablissement_id',
        'professionnel_id',
        'forfait_pro_id',
        'date_debut',
        'date_fin',
    ];

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function forfait_pro()
    {
        return $this->belongsTo(Forfait_pro::class);
    }
}
