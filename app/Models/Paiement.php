<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Paiement extends Model
{
    use HasFactory;
    protected $table = 'paiements';
    protected $fillable = [
        'referenceNumber',
        'amount',
        'description',
        'countryCurrencyCode',
        'customerEmail',
        'customerFirstName',
        'customerLastname',
        'customerPhoneNumber',
        'professionnel_id',
        'forfait_pro_id',
        'user_id',
        'forfait_id',
        'statut',
        'fineopay_reference',
        'checkout_link',
        'reponse_api',
        'date_debut',
        'date_fin'
    ];

    protected $casts = [
        'reponse_api' => 'array',
        'date_debut' => 'datetime',
        'date_fin' => 'datetime'
    ];

    public function professionnel()
    {
        return $this->belongsTo(Professionnel::class);
    }

    public function forfait_pro()
    {
        return $this->belongsTo(Forfait_pro::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function forfait()
    {
        return $this->belongsTo(Forfait::class);
    }
}
