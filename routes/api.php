<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CommercialController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });




// Préfixe pour API v1
Route::prefix('v1')->group(function () {
    // Routes protégées par le middleware d'authentification
    Route::middleware('auth:api')->group(function () {
        // Route de déconnexion
        Route::post('logout', [CommercialController::class, 'logout']);

        // Route pour obtenir les informations de l'utilisateur
        Route::post('user-infos', [CommercialController::class, 'getUser']);
        Route::get('commercial-profile', [CommercialController::class, 'commercialProfile']);
        Route::get('commercial-wallet/transactions', [CommercialController::class, 'walletTransactions']);

        // Route pour mettre à jour les informations de l'utilisateur
        Route::post('/user-update/{id}', [CommercialController::class, 'updateUser']);

        // Route pour mettre à jour le mot de passe
        Route::post('/password-update/{id}', [CommercialController::class, 'updatePassword']);

        // Route pour afficher les prostects
        Route::get('/index-prospector', [CommercialController::class, 'indexProspector']);
        // Route pour enregistrer un prostect
        Route::post('/store-prospector', [CommercialController::class, 'storeProspectorWithEtablissement']);
        // Route pour mettre à jour un prostect
        Route::post('/update-prospector/{id}', [CommercialController::class, 'updateProspector']);
        // Route pour afficher les details d'un prostect
        Route::get('/show-prospector/{id}', [CommercialController::class, 'showProspector']);
        // Route pour mettre à jour un établissement prospecté
        Route::post('/update-etablissement/{id}', [CommercialController::class, 'updateEtablissement']);
        // Route pour afficher les types etablissements
        Route::get('/get-type-etablissement', [CommercialController::class, 'getTypeEtablissement']);

        Route::post('/register-usager', [CommercialController::class, 'registerUsager']);
        Route::get('/get-usager', [CommercialController::class, 'getUsagerByCommercial']);

        // Route pour enregistrer un paiement
        Route::post('/store-paiement', [CommercialController::class, 'storePaiement']);
        Route::post('/verify-paiement', [CommercialController::class, 'verifierStatutPaiementApi']);

        // Route pour enregistrer un paiement
        Route::post('/store-paiement-usager', [CommercialController::class, 'storePaiementUsager']);
        Route::post('/verify-paiement-usager', [CommercialController::class, 'verifierStatutPaiementApiUsager']);


        Route::get('/get-pays', [CommercialController::class, 'getPays']);
		Route::get('/get-ville', [CommercialController::class, 'getVille']);
		Route::get('/get-commune', [CommercialController::class, 'getCommune']);
		Route::get('/get-type-etablissement', [CommercialController::class, 'getTypeEtablissement']);
		Route::get('/get-type-de-prestation', [CommercialController::class, 'getTypeDePrestation']);
		Route::get('/get-forfait-pro', [CommercialController::class, 'getForfaitPro']);
		Route::get('/get-forfait-usager', [CommercialController::class, 'getForfaitUsager']);

		// Routes pour les stations services
        Route::get('/station-services', [CommercialController::class, 'indexStationService']);
        Route::post('/station-services', [CommercialController::class, 'storeStationService']);
        Route::get('/station-services/{id}', [CommercialController::class, 'showStationService']);
        Route::post('/station-services/{id}', [CommercialController::class, 'updateStationService']);
        Route::delete('/station-services/{id}', [CommercialController::class, 'deleteStationService']);

        // Route pour créer une station de lavage et son compte lavage
        Route::post('/station-de-lavages/register', [CommercialController::class, 'storeStationDeLavageWithAccount']);
        Route::post('/station-de-lavages/{stationDeLavageId}/lavages/{lavageId}', [CommercialController::class, 'updateStationDeLavageWithAccount']);

		// Route pour enregistrer un vehicule
        Route::post('/store-vehicule', [CommercialController::class, 'storeVehicule']);
        Route::post('/update-vehicule/{id}', [CommercialController::class, 'updateVehicule']);


		// Route pour les type de vehicule
        Route::get('/type-de-vehicule', [CommercialController::class, 'indexTypeDeVehicule']);

        // Route pour les type de carburant
        Route::get('/type-de-carburant', [CommercialController::class, 'indexTypeDeCarburant']);

        // Route pour la liste des marques
        Route::get('/list-marque-all', [CommercialController::class, 'indexMarque']);

        // Route pour la liste des marques
        Route::get('/list-des-vehicules', [CommercialController::class, 'indexVehicule']);

        Route::post('/qrcodes/assign-scan', [CommercialController::class, 'assignByScan']);
        Route::get('/qrcodes/history/{commercial_id}', [CommercialController::class, 'historyByCommercial']);

    });

    // Routes publiques
    Route::post('register', [CommercialController::class, 'register']);

    Route::post('login', [CommercialController::class, 'login']);

    // Route pour verifier si le numero de telephone existe
    Route::post('otp-auth', [CommercialController::class, 'sendOtpForAuth']);

    // Route pour verifier si le otp est correcte
    Route::post('verify-otp-auth', [CommercialController::class, 'verifyOtp']);

    // Route pour verifier si le otp est correcte
    Route::post('send-otp-password-forget', [CommercialController::class, 'sendOtpForPasswordForget']);

    // Route pour verifier le otp de mot de passe oublié
    Route::post('verify-otp-password-forget', [CommercialController::class, 'verifyOtpPasswordForget']);

    // Route pour mettre a jour le mot de passe oublié
    Route::post('update-password-forget', [CommercialController::class, 'passwordForgetUpdate']);

});

Route::post('/v1/fineopay/callback', [CommercialController::class, 'fineoPayCallback']);
