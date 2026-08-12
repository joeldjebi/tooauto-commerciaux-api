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

    public function lavage()
    {
        return $this->belongsTo(Lavage::class, 'created_by');
    }
}
