<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ville extends Model
{
    use HasFactory;
    protected $table = 'villes';
    protected $fillable = [
        'nom',
        'pays_id',
    ];

    public function communes()
    {
        return $this->hasMany(Commune::class);
    }

    public function etablissements()
    {
        return $this->hasMany(Etablissement::class);
    }
}