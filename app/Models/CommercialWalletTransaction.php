<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommercialWalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'commercial_wallet_id',
        'commercial_id',
        'user_id',
        'abonnement_usager_id',
        'forfait_id',
        'direction',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'commission_type',
        'commission_value',
        'reference',
        'description',
        'created_by',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'commission_value' => 'decimal:2',
        'meta' => 'array',
    ];

    public function wallet()
    {
        return $this->belongsTo(CommercialWallet::class, 'commercial_wallet_id');
    }

    public function commercial()
    {
        return $this->belongsTo(Commercial::class, 'commercial_id');
    }
}
