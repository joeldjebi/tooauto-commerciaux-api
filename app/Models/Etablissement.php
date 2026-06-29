<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Etablissement extends Model
{
    use HasFactory;
    protected $table = 'etablissements';
    protected $fillable = [
        'name',
        'mobile',
        'email',
        'description',
        'logo',
        'cover',
        'adresse',
        'longitude',
        'latitude',
        'professionnel_id',
        'pays_id',
        'ville_id',
        'commune_id',
        'type_etablissement_id',
        'specialite',
        'categorie_service_id',
        'statut',
        'is_whatsapp',
        'mobile_fix',
        'type_de_prestations',
        'service_mobile',
        'cover_create_by',
        'logo_create_by',
        'code_parrain',
        'adresse_map'
    ];

    public function professionnel()
    {
        return $this->belongsTo(Professionnel::class);
    }

    public function pays()
    {
        return $this->belongsTo(Pays::class);
    }

    public function ville()
    {
        return $this->belongsTo(Ville::class);
    }

    public function commune()
    {
        return $this->belongsTo(Commune::class);
    }

    public function type_etablissement()
    {
        return $this->belongsTo(Type_etablissement::class);
    }

    public function categorie_service()
    {
        return $this->belongsTo(Categorie_service::class);
    }

    public function type_de_prestations()
    {
        return $this->belongsTo(Type_de_prestation::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }

    public function abonnement_pros()
    {
        return $this->hasMany(Abonnement_pro::class);
    }
}