<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AbonnementUsager extends Model
{
    use HasFactory;
    protected $table = 'abonnement_usagers';
    protected $fillable = [
        'user_id',
        'forfait_id',
        'date_debut',
        'date_fin',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function forfait()
    {
        return $this->belongsTo(Forfait::class);
    }

    public function paiement()
    {
        return $this->hasOne(Paiement::class);
    }

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

}