<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StationService extends Model
{
    use HasFactory;

    protected $table = 'station_services';

    protected $fillable = [
        'name',
        'ville_id',
        'commune_id',
        'email',
        'mobile',
        'adresse',
        'longitude',
        'latitude',
        'adresse_map',
        'borne_electrique',
        'statut',
        'station_electrique',
        'nuit',
        'logo',
        'created_by',
    ];

    protected $casts = [
        'ville_id' => 'integer',
        'commune_id' => 'integer',
        'borne_electrique' => 'integer',
        'statut' => 'integer',
        'station_electrique' => 'integer',
        'nuit' => 'integer',
        'created_by' => 'integer',
    ];

    public function ville()
    {
        return $this->belongsTo(Ville::class);
    }

    public function commune()
    {
        return $this->belongsTo(Commune::class);
    }

    public function creator()
    {
        return $this->belongsTo(Commercial::class, 'created_by');
    }

    public function stationAccount()
    {
        return $this->hasOne(Station::class, 'station_service_id');
    }
}
