<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommercialWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'commercial_id',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function commercial()
    {
        return $this->belongsTo(Commercial::class, 'commercial_id');
    }

    public function transactions()
    {
        return $this->hasMany(CommercialWalletTransaction::class, 'commercial_wallet_id');
    }
}
