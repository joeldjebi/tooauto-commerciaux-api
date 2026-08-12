<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Lavage extends Authenticatable
{
    use HasFactory;

    protected $table = 'lavages';

    protected $fillable = [
        'role',
        'first_name',
        'last_name',
        'mobile',
        'email',
        'password',
        'statut',
        'created_by',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'role' => 'integer',
        'statut' => 'integer',
        'created_by' => 'integer',
    ];
}
