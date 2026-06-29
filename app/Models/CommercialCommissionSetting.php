<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommercialCommissionSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'value',
        'statut',
        'created_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
    ];
}
