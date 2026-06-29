<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    use HasFactory;

    protected $table = 'stations';

    protected $fillable = [
        'first_name',
        'last_name',
        'mobile',
        'email',
        'password',
        'statut',
        'role',
        'created_by',
        'station_service_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'statut' => 'integer',
        'role' => 'integer',
        'created_by' => 'integer',
        'station_service_id' => 'integer',
    ];

    public function stationService()
    {
        return $this->belongsTo(StationService::class, 'station_service_id');
    }
}
