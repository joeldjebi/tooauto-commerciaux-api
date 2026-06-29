<?php

namespace App\Services;

use App\Models\AbonnementUsager;
use App\Models\CommercialCommissionSetting;
use App\Models\CommercialWallet;
use App\Models\CommercialWalletTransaction;
use Illuminate\Support\Facades\DB;

class CommercialWalletService
{
    public function creditCommissionForAbonnement(AbonnementUsager $abonnement): ?CommercialWalletTransaction
    {
        $abonnement->loadMissing(['user', 'forfait']);

        $user = $abonnement->user;
        $forfait = $abonnement->forfait;
        $commercialId = (int) ($user->commercial_id ?? 0);
        $forfaitPrice = (float) ($forfait->prix ?? 0);

        if ($commercialId <= 0 || $forfaitPrice <= 0) {
            return null;
        }

        if (CommercialWalletTransaction::where('abonnement_usager_id', $abonnement->id)->where('type', 'commission')->exists()) {
            return null;
        }

        $setting = $this->activeCommissionSetting();
        $commissionType = $setting['type'];
        $commissionValue = (float) $setting['value'];
        $amount = $this->calculateCommissionAmount($forfaitPrice, $commissionType, $commissionValue);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($abonnement, $user, $forfait, $commercialId, $commissionType, $commissionValue, $amount) {
            $wallet = CommercialWallet::where('commercial_id', $commercialId)->lockForUpdate()->first();

            if (!$wallet) {
                $wallet = CommercialWallet::create([
                    'commercial_id' => $commercialId,
                    'balance' => 0,
                ]);
                $wallet = CommercialWallet::where('commercial_id', $commercialId)->lockForUpdate()->first();
            }

            if (CommercialWalletTransaction::where('abonnement_usager_id', $abonnement->id)->where('type', 'commission')->exists()) {
                return null;
            }

            $balanceBefore = (float) $wallet->balance;
            $balanceAfter = $balanceBefore + $amount;
            $wallet->balance = $balanceAfter;
            $wallet->save();

            return CommercialWalletTransaction::create([
                'commercial_wallet_id' => $wallet->id,
                'commercial_id' => $commercialId,
                'user_id' => $user->id,
                'abonnement_usager_id' => $abonnement->id,
                'forfait_id' => $forfait->id ?? $abonnement->forfait_id,
                'direction' => 'credit',
                'type' => 'commission',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'commission_type' => $commissionType,
                'commission_value' => $commissionValue,
                'description' => 'Commission abonnement usager ' . ($forfait->libelle ?? ''),
                'meta' => [
                    'forfait_price' => $forfait->prix ?? null,
                    'forfait_libelle' => $forfait->libelle ?? null,
                ],
            ]);
        });
    }

    public function activeCommissionSetting(): array
    {
        $setting = CommercialCommissionSetting::where('statut', 1)->latest()->first();

        if ($setting) {
            return [
                'type' => $setting->type,
                'value' => (float) $setting->value,
            ];
        }

        return [
            'type' => env('COMMERCIAL_COMMISSION_TYPE', 'fixed'),
            'value' => (float) env('COMMERCIAL_COMMISSION_VALUE', 0),
        ];
    }

    public function calculateCommissionAmount(float $forfaitPrice, string $type, float $value): float
    {
        if ($type === 'percentage') {
            return round(($forfaitPrice * $value) / 100, 2);
        }

        return round($value, 2);
    }
}
