<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Carbon\Carbon;

class Commercial extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    
    
    
        /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    
    /**
     * Obtenir l'identifiant pour le sujet JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey(); // Généralement, l'ID de l'utilisateur
    }

    /**
     * Obtenir les revendications personnalisées pour le JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
    public function wallet()
    {
        return $this->hasOne(CommercialWallet::class, 'commercial_id');
    }

    public function walletTransactions()
    {
        return $this->hasMany(CommercialWalletTransaction::class, 'commercial_id');
    }
}
