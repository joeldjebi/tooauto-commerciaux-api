<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StationDeLavage extends Model
{
    use HasFactory;

    protected $table = 'station_de_lavages';

    protected $fillable = [
        'name',
        'contact',
        'longitude',
        'latitude',
        'adresse',
        'logo',
        'statut',
        'created_by',
    ];

    protected $casts = [
        'statut' => 'integer',
        'created_by' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(Commercial::class, 'created_by');
    }
}
