<?php

namespace App\Http\Controllers;

use App\Models\Commercial;
use App\Models\CommercialWallet;
use App\Models\CommercialWalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use App\Models\User;
use App\Models\Vehicule;
use App\Models\Marque;
use App\Models\QrcodeAssignment;
use App\Models\QrcodeGenerate;
use App\Models\Type_de_carburant;
use App\Models\Alert;
use App\Models\Type_de_vehicule;
use App\Models\Verify_code;
use App\Models\Type_de_prestation;
use App\Models\Parrain;
use App\Models\Prospecter;
use App\Models\Type_etablissement;
use App\Models\Professionnel;
use App\Models\Etablissement;
use App\Models\Forfait_pro;
use App\Models\Abonnement_pro;
use App\Models\Paiement;
use App\Models\AbonnementUsager;
use App\Models\Forfait;
use App\Models\Commune;
use App\Models\Ville;
use App\Models\Pays;
use App\Models\StationService;
use App\Models\Station;
use App\Models\Lavage;
use App\Models\StationDeLavage;
use Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Response;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Services\WasabiService;
use App\Services\SmsService;
use App\Services\FineoPayService;
use App\Services\CommercialWalletService;

class CommercialController extends Controller
{
    protected $wasabiService;
    protected $smsService;
    protected FineoPayService $fineoPayService;

        /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct(WasabiService $wasabiService, SmsService $smsService, FineoPayService $fineoPayService) {
        $this->wasabiService = $wasabiService;
        $this->smsService = $smsService;
        $this->fineoPayService = $fineoPayService;
        $this->middleware('auth:api', ['except' => [
            'login', 'register', 'sendOtpForAuth', 'verifyOtp',
            'verifyNumberPasswordForget', 'passwordForgetUpdate',
            'sendOtpForPasswordForget', 'verifyOtpPasswordForget', 'indexPaysAll',
            'fineoPayCallback'
        ]]);
    }

    public function register(Request $request): JsonResponse
    {
        $uploadErrors = $this->commercialRegisterUploadErrors($request);

        if (!empty($uploadErrors)) {
            return response()->json([
                'success' => false,
                'message' => 'Un ou plusieurs fichiers n\'ont pas pu être reçus par le serveur.',
                'errors' => $uploadErrors,
                'limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                ],
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:100',
            'prenoms' => 'required|string|max:200',
            'mobile' => 'required|string|max:20|unique:commercials,mobile',
            'password' => 'required|string|min:6',
            'is_whatapps' => 'required|integer|in:0,1',
            'super_id' => 'nullable|integer',
            'email' => 'nullable|email|unique:commercials,email',
            'parrain_id' => 'nullable|integer',
            'piece_recto' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,heic,heif,pdf|max:25048',
            'piece_verso' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,heic,heif,pdf|max:25048',
            'photo' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,heic,heif|max:25048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $commercial = new Commercial();
            $commercial->nom = html_entity_decode($request->nom);
            $commercial->prenoms = html_entity_decode($request->prenoms);
            $commercial->mobile = html_entity_decode($request->mobile);
            $commercial->password = Hash::make($request->password);
            $commercial->statut = 0;
            $commercial->etat = 0;
            $commercial->super_id = $request->super_id;
            $commercial->parrain_id = $request->parrain_id;
            $commercial->is_whatapps = (int) $request->is_whatapps;
            $commercial->email = $request->email;

            if ($request->hasFile('piece_recto')) {
                $commercial->piece_recto = $this->wasabiService->uploadFile(
                    $request->file('piece_recto'),
                    'commercials/pieces',
                    'piece-recto'
                );
            }

            if ($request->hasFile('piece_verso')) {
                $commercial->piece_verso = $this->wasabiService->uploadFile(
                    $request->file('piece_verso'),
                    'commercials/pieces',
                    'piece-verso'
                );
            }

            if ($request->hasFile('photo')) {
                $commercial->photo = $this->wasabiService->uploadFile(
                    $request->file('photo'),
                    'commercials/photos',
                    'photo'
                );
            }

            $commercial->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Commercial créé avec succès. Le compte est en attente d\'activation.',
                'data' => $commercial,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création du commercial', ['message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la création du commercial.',
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

    protected function commercialRegisterUploadErrors(Request $request): array
    {
        $errors = [];

        foreach (['piece_recto', 'piece_verso', 'photo'] as $field) {
            $file = $request->file($field);

            if (!$file) {
                continue;
            }

            foreach ((array) $file as $uploadedFile) {
                if (!$uploadedFile || !($uploadedFile instanceof UploadedFile)) {
                    continue;
                }

                if ($uploadedFile->isValid() || $uploadedFile->getError() === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $errors[$field][] = $this->uploadErrorMessage($uploadedFile->getError());
            }
        }

        return $errors;
    }

    protected function uploadErrorMessage(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Le fichier dépasse la taille maximale autorisée par PHP. Augmentez upload_max_filesize et post_max_size sur le serveur.';
            case UPLOAD_ERR_PARTIAL:
                return 'Le fichier n\'a été uploadé que partiellement. Veuillez réessayer.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Le dossier temporaire PHP est introuvable sur le serveur.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Le serveur n\'a pas pu écrire le fichier temporaire.';
            case UPLOAD_ERR_EXTENSION:
                return 'Une extension PHP a interrompu l\'upload du fichier.';
            default:
                return 'Le fichier n\'a pas pu être uploadé.';
        }
    }


    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendOtpForAuth(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Rechercher l'utilisateur avec l'indicatif et le numéro
        $userNumberVerify = Commercial::where(['mobile' => $request->mobile, 'statut' => 1])->first();

        if (empty($userNumberVerify)) {
            return response()->json([
                'error' => true,
                'message' => 'Votre compte est inactif, veuillez contacter l\'administrateur.',
            ], 404);
        }

        if ($userNumberVerify->etat == 1) {
            return response()->json([
                'error' => false,
                'message' => 'Votre compte est actif, veuillez mettre a jour votre mot de passe',
            ], 403);
        }

        // Générer le code de confirmation
        $confirmationCode = rand(1000, 9999);
        $mobileWithIndicatif = $request->mobile;

        // Construire le message
        $message = strtoupper("Votre code de confirmation: " . $confirmationCode);

        // Envoyer le SMS
        try {
            $this->smsService->sendSmsMtarget(
                $message,
                $mobileWithIndicatif,
                config('services.mtarget.sender', 'TOO AUTO')
            );

            // Enregistrer le code de vérification
            Verify_code::where('mobile', $mobileWithIndicatif)
                ->where('statut', 0)
                ->update(['statut' => 1]);

            $verifyCode = new Verify_code();
            $verifyCode->code = $confirmationCode;
            $verifyCode->mobile = $mobileWithIndicatif;
            $verifyCode->statut = 0;

            if (!$verifyCode->save()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une erreur est survenue lors de l\'enregistrement du code.',
                ], 500);
            }
        } catch (\Exception $e) {
            // Enregistrer l'erreur dans les logs
            \Log::error('Erreur lors de l\'envoi du SMS : ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'envoi du SMS.',
                'error' => $e->getMessage(),
            ], 500);
        }

        // Retourner la réponse avec le code de confirmation
        return response()->json([
            'success' => true,
            'message' => 'Code de confirmation envoyé par SMS.',
            'code' => config('app.debug') ? $confirmationCode : null,
        ], 200);
    }

    public function verifyOtp(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
            'otp' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Rechercher l'OTP dans la table Verify_codes
        $verifyCode = Verify_code::where('mobile', $request->mobile)
                                ->where('code', $request->otp)
                                ->where('statut', 0)
                                ->first();

        // Vérifier si l'OTP existe et a le statut 0
        if (empty($verifyCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Le code OTP est invalide ou a déjà été utilisé.',
            ], 404);
        }

        // Mettre à jour le statut de l'OTP pour indiquer qu'il a été utilisé
        $verifyCode->statut = 1;

        if (!$verifyCode->save()) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la validation de l\'OTP.',
            ], 500);
        }

        // Retourner une réponse réussie
        return response()->json([
            'success' => true,
            'message' => 'Le code OTP est valide.',
        ], 200);
    }


    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $credentials = $request->only(['mobile', 'password']);
        $mobileWithoutIndicatif = $credentials['mobile'] ?? null;
        $user = Commercial::where(['mobile' => $mobileWithoutIndicatif])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Identifiants incorrects. Veuillez vérifier votre numéro et votre mot de passe.',
            ], 401);
        }

        if ((int) $user->statut === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Votre compte est désactiver, veuillez contacter l\'administrateur',
            ], 500);
        }

        if (Hash::check($request->password, $user->password)) {
            $token = JWTAuth::fromUser($user);
            $expirationTime = now()->addMinutes(config('jwt.ttl'))->timestamp;

            $user->etat = 1;
            $user->save();
            $user->loadMissing('wallet');

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur authentifié avec succès.',
                'access_token' => $token,
                'expiration_time' => $expirationTime,
                'user' => $this->commercialApiPayload($user),
                'wallet' => $this->commercialWalletPayload($user),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Identifiants incorrects. Veuillez vérifier votre numéro et votre mot de passe.',
        ], 401);
    }

    public function walletTransactions(Request $request): JsonResponse
    {
        $commercial = auth()->user();

        if (!$commercial) {
            return response()->json([
                'success' => false,
                'message' => 'Commercial non authentifié.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'direction' => 'nullable|in:credit,debit',
            'type' => 'nullable|string|max:50',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les filtres fournis ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = CommercialWalletTransaction::where('commercial_id', $commercial->id)
            ->orderBy('id', 'desc');

        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = (int) $request->get('per_page', 25);
        $transactions = $query->paginate($perPage);
        $wallet = CommercialWallet::firstOrCreate(
            ['commercial_id' => $commercial->id],
            ['balance' => 0]
        );

        return response()->json([
            'success' => true,
            'message' => 'Liste des transactions wallet récupérée avec succès.',
            'data' => [
                'wallet' => [
                    'id' => $wallet->id,
                    'commercial_id' => $commercial->id,
                    'balance' => (float) $wallet->balance,
                    'total_credit' => (float) CommercialWalletTransaction::where('commercial_id', $commercial->id)->where('direction', 'credit')->sum('amount'),
                    'total_debit' => (float) CommercialWalletTransaction::where('commercial_id', $commercial->id)->where('direction', 'debit')->sum('amount'),
                ],
                'transactions' => $transactions->items(),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ],
            ],
        ]);
    }
    public function commercialProfile(): JsonResponse
    {
        $commercial = auth()->user();

        if (!$commercial) {
            return response()->json([
                'success' => false,
                'message' => 'Commercial non authentifié.',
            ], 401);
        }

        $commercial->loadMissing('wallet');

        return response()->json([
            'success' => true,
            'message' => 'Informations du commercial récupérées avec succès.',
            'data' => [
                'commercial' => $this->commercialApiPayload($commercial),
                'wallet' => $this->commercialWalletPayload($commercial, 25),
            ],
        ]);
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth()->logout();
        return response()->json(['message' => 'User successfully signed out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return $this->createNewToken(auth()->refresh());
    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUser()
    {
        return response()->json([
            'user' => auth()->user(),
        ]);
    }

    public function updatePassword(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6',
            'confirm_password' => 'required|string|same:new_password',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $commercial = auth()->user();

        if (!$commercial || (int) $commercial->id !== (int) $id) {
            return response()->json([
                'success' => false,
                'message' => 'Action non autorisée.',
            ], 403);
        }

        $commercial = Commercial::find($id);

        if (!$commercial) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable.',
            ], 404);
        }

        if (!Hash::check($request->current_password, $commercial->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Le mot de passe actuel est incorrect.',
            ], 422);
        }

        $commercial->password = Hash::make($request->new_password);
        $commercial->save();

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe modifié avec succès.',
        ], 200);
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function createNewToken($token)
    {
        if ($usr->save()) {
            return response()->json([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
                'user' => auth()->user(),
            ]);
        }
    }

    public function sendMessageConfirmOrder($message, $reciever)
    {
        return $this->smsService->sendSmsMtarget(
            $message,
            $reciever,
            config('services.mtarget.sender', 'TOO AUTO')
        );
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendOtpForPasswordForget(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Rechercher l'utilisateur avec l'indicatif et le numéro
        $userNumberVerify = Commercial::where('mobile', $request->mobile)->first();

        if (empty($userNumberVerify)) {
            return response()->json([
                'error' => true,
                'message' => 'Numéro de téléphone incorrecte.',
            ], 404);
        }

        // Générer le code de confirmation
        $confirmationCode = rand(1000, 9999);
        $mobileWithIndicatif = $request->mobile;

        // Construire le message
        $message = strtoupper("Votre code de confirmation: " . $confirmationCode);

        // Envoyer le SMS
        try {
            $this->smsService->sendSmsMtarget(
                $message,
                $mobileWithIndicatif,
                config('services.mtarget.sender', 'TOO AUTO')
            );

            // Enregistrer le code de vérification
            Verify_code::where('mobile', $mobileWithIndicatif)
                ->where('statut', 0)
                ->update(['statut' => 1]);

            $verifyCode = new Verify_code();
            $verifyCode->code = $confirmationCode;
            $verifyCode->mobile = $mobileWithIndicatif;
            $verifyCode->statut = 0;

            if (!$verifyCode->save()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une erreur est survenue lors de l\'enregistrement du code.',
                ], 500);
            }
        } catch (\Exception $e) {
            // Enregistrer l'erreur dans les logs
            \Log::error('Erreur lors de l\'envoi du SMS : ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'envoi du SMS.',
                'error' => $e->getMessage(),
            ], 500);
        }

        // Retourner la réponse avec le code de confirmation
        return response()->json([
            'success' => true,
            'message' => 'Code de confirmation envoyé par SMS.',
            'code' => config('app.debug') ? $confirmationCode : null,
        ], 200);
    }

    public function verifyOtpPasswordForget(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
            'otp' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Rechercher l'OTP dans la table Verify_codes
        $verifyCode = Verify_code::where('mobile', $request->mobile)
                                ->where('code', $request->otp)
                                ->where('statut', 0)
                                ->first();

        // Vérifier si l'OTP existe et a le statut 0
        if (empty($verifyCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Le code OTP est invalide ou a déjà été utilisé.',
            ], 404);
        }

        // Retourner une réponse réussie
        return response()->json([
            'success' => true,
            'message' => 'Le code OTP est valide.',
        ], 200);
    }

    /**
     * Mot de passe oublié
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function passwordForgetUpdate(Request $request)
    {
        // Validation des données
        $validator = Validator::make($request->all(), [
            'otp' => 'required|numeric',
            'mobile' => 'required|numeric',
            'new_password' => 'required|string|min:6',
            'confirm_password' => 'required|string|min:6|same:new_password', // Vérification directe
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation des données échouée',
                'errors' => $validator->errors(),
            ], 422);
        }

        $mobile = $request->mobile;

        // Recherche de l'utilisateur
        $user = Commercial::where(['mobile' => $mobile])->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Utilisateur introuvable',
            ], 404); // Code HTTP 404 pour "Non trouvé"
        }

        // Vérification du code de réinitialisation
        $verifyCode = Verify_code::where([
            'mobile' => $mobile,
            'statut' => 0,
            'code' => $request->otp,
        ])->first();

        if (!$verifyCode) {
            return response()->json([
                'status' => false,
                'message' => 'Code de vérification invalide ou expiré',
            ], 400); // Code HTTP 400 pour "Mauvaise requête"
        }

        // Modification du mot de passe dans une transaction
        try {
            DB::beginTransaction();
            $user->password = Hash::make($request->new_password);
            $user->save();

            // Invalider le code de vérification après utilisation.
            $verifyCode->statut = 1;
            $verifyCode->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Votre mot de passe a été modifié avec succès',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la modification du mot de passe',
                'error' => $e->getMessage(), // À supprimer en production
            ], 500); // Code HTTP 500 pour "Erreur serveur"
        }
    }


    // Afficher tous les prospecteurs
	public function indexProspector()
	{
		try {
            $codeParrain = Parrain::where('commercial_id', auth()->user()->id)->first()->code ?? null;
            $codeParrain = $codeParrain ?? null;

			$etablissements = Etablissement::where('code_parrain', $codeParrain)
            ->with([
				'professionnel',
				'pays',
				'ville',
				'commune',
				'type_etablissement',
				'categorie_service',
				'abonnement_pros' => function($query) {
					$query->latest();
				}
			])
			->orderBy('created_at', 'desc')
			->get();

			if ($etablissements->isEmpty()) {
				return response()->json([
					'success' => false,
					'message' => 'Aucun établissement trouvé.',
				], 404);
			}

			return response()->json([
				'success' => true,
				'message' => 'Liste des établissements récupérée avec succès.',
				'data' => $etablissements->map(function ($etablissement) {
					return $this->attachEtablissementMediaUrls($etablissement);
				})
			], 200);

		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Une erreur est survenue lors de la récupération des établissements.',
				'error' => $e->getMessage()
			], 500);
		}
	}


    // Créer un nouveau prospecteur
    public function storeProspectorWithEtablissement(Request $request)
    {
        // Validation des données du prospecteur
        $validatorProspector = Validator::make($request->all(), [
            'nom' => 'required|string|max:255',
            'prenoms' => 'required|string|max:255',
            'mobile' => 'required|string|max:20|unique:professionnels',
        ]);

        if ($validatorProspector->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données du prospecteur ne sont pas valides.',
                'errors' => $validatorProspector->errors()
            ], 422);
        }

        // Validation des données de l'établissement
        $validatorEtablissement = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:etablissements,email',
            'adresse' => 'required|string|max:255',
            'adresse_map' => 'nullable|string|max:255',
            'longitude' => 'required|string|max:255',
            'latitude' => 'required|string|max:255',
            'pays_id' => 'required|integer|exists:pays,id',
            'ville_id' => 'required|integer|exists:villes,id',
            'commune_id' => 'required|integer|exists:communes,id',
            'type_etablissement_id' => 'required|integer|exists:type_etablissements,id',
            'specialite' => 'nullable|string',
            'service_mobile' => 'nullable|string',
            'description' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:8048',
            'cover' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:8048',
            'is_whatsapp' => 'required|string',
            'mobile_fix' => 'nullable|string',
            'type_de_prestation' => 'required|string',
        ]);

        if ($validatorEtablissement->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données de l\'établissement ne sont pas valides.',
                'errors' => $validatorEtablissement->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Création du prospecteur
            $randomPassword = rand(100000, 999999);

            $professionnel = Professionnel::create([
                'nom' => html_entity_decode($request->nom),
                'prenoms' => html_entity_decode($request->prenoms),
                'role' => '01',
                'mobile' => html_entity_decode($request->mobile),
                'password' => Hash::make($randomPassword),
            ]);

            if (!$professionnel) {
                throw new \Exception("Erreur lors de la création du prospecteur");
            }

            // Envoi du mot de passe par SMS
            $message = strtoupper(
                "Votre compte a ete cree avec succes\n" .
                "Voici vos identifiants de connexion :\n" .
                "Numero de telephone : " . $request->mobile . "\n" .
                "Mot de passe : " . $randomPassword . "\n" .
                "Telecharger Tooauto  : https://tooauto.com/link-app"
            );

            $smsResponse = $this->sendMessageConfirmOrder($message, $request->mobile);

            // Gestion des images via Wasabi
            $logoPath = $coverPath = null;

            if ($request->hasFile('logo')) {
                $logoPath = $this->wasabiService->uploadFile(
                    $request->file('logo'),
                    'etablissement/logo',
                    'logo'
                );
            }

            if ($request->hasFile('cover')) {
                $coverPath = $this->wasabiService->uploadFile(
                    $request->file('cover'),
                    'etablissement/cover',
                    'cover'
                );
            }

            // Traitement du type_de_prestation
            try {
                $typeDePrestation = json_decode($request->type_de_prestation, true);
                if (!is_array($typeDePrestation)) {
                    throw new \Exception("Le format des types de prestation est invalide");
                }
                $typeDePrestation = array_map('intval', $typeDePrestation);
                $type_de_prestations = json_encode($typeDePrestation, JSON_THROW_ON_ERROR);
            } catch (\Exception $e) {
                throw new \Exception("Erreur lors du traitement des types de prestation: " . $e->getMessage());
            }

            $typeEtablissementToCategorie = [
                1 => 3,
                2 => 2,
                3 => 2,
                4 => 2,
                5 => 5,
                6 => 2,
                7 => 2,
            ];

            $categorieService = $typeEtablissementToCategorie[$request->type_etablissement_id] ?? 0;

            $codeParrain = Parrain::where('commercial_id', auth()->user()->id)->first()->code ?? null;
            $codeParrain = $codeParrain ?? null;

            // Création de l'établissement (email vide → null pour éviter violation UNIQUE)
            $emailEtablissement = $request->filled('email') && trim((string) $request->email) !== ''
                ? html_entity_decode($request->email)
                : null;

            $etablissement = Etablissement::create([
                'name' => html_entity_decode($request->name),
                'mobile' => html_entity_decode($request->mobile),
                'email' => $emailEtablissement,
                'description' => html_entity_decode($request->description),
                'logo' => $logoPath,
                'cover' => $coverPath,
                'adresse' => html_entity_decode($request->adresse),
                'longitude' => $request->longitude,
                'latitude' => $request->latitude,
                'professionnel_id' => $professionnel->id,
                'pays_id' => $request->pays_id,
                'ville_id' => $request->ville_id,
                'commune_id' => $request->commune_id,
                'type_etablissement_id' => $request->type_etablissement_id,
                'specialite' => $request->specialite,
                'categorie_service_id' => $categorieService,
                'statut' => 1,
                'is_whatsapp' => $request->is_whatsapp,
                'mobile_fix' => $request->mobile_fix,
                'type_de_prestations' => $type_de_prestations,
                'service_mobile' => $request->service_mobile,
                'cover_create_by' => 2, // 1-Etablissement, 2-Commercial
                'logo_create_by' => 2, // 1-Etablissement, 2-Commercial
                'code_parrain' => $codeParrain,
				'is_commercial' => 1,
                'adresse_map' => $request->adresse_map,
            ]);

            // Récupération du forfait "free" et création de l'abonnement
            $forfaitFreePro = Forfait_pro::where('nom', 'POPULAIRE')->first();
            if (!$forfaitFreePro) {
                throw new \Exception("Le forfait 'Free' est introuvable");
            }

            $dateDebut = Carbon::now();
            $dateFin = $dateDebut->copy()->addMonths($forfaitFreePro->duree);

            Abonnement_pro::create([
                'etablissement_id' => $etablissement->id,
                'forfait_pro_id' => $forfaitFreePro->id,
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Prospecteur et établissement créés avec succès.",
                'data' => [
                    'professionnel' => $professionnel,
                    'etablissement' => $this->attachEtablissementMediaUrls($etablissement)
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la création du prospecteur et de l'établissement.",
                'error' => $e->getMessage()
            ], 500);
        }
    }

	    public function updateEtablissement(Request $request, $id)
    {
        $etablissement = Etablissement::with('professionnel')->find($id);

        if (!$etablissement) {
            return response()->json([
                'success' => false,
                'message' => 'Établissement introuvable.',
            ], 404);
        }

        $professionnelId = $etablissement->professionnel_id;

        $validator = Validator::make($request->all(), [
            'nom' => 'sometimes|required|string|max:255',
            'prenoms' => 'sometimes|required|string|max:255',
            'mobile' => 'sometimes|required|string|max:20|unique:professionnels,mobile,' . $professionnelId,
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255|unique:etablissements,email,' . $etablissement->id,
            'adresse' => 'sometimes|required|string|max:255',
            'adresse_map' => 'nullable|string|max:255',
            'longitude' => 'sometimes|required|string|max:255',
            'latitude' => 'sometimes|required|string|max:255',
            'pays_id' => 'sometimes|required|integer|exists:pays,id',
            'ville_id' => 'sometimes|required|integer|exists:villes,id',
            'commune_id' => 'sometimes|required|integer|exists:communes,id',
            'type_etablissement_id' => 'sometimes|required|integer|exists:type_etablissements,id',
            'specialite' => 'nullable|string',
            'service_mobile' => 'nullable|string',
            'description' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:8048',
            'cover' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:8048',
            'is_whatsapp' => 'sometimes|required|string',
            'mobile_fix' => 'nullable|string',
            'type_de_prestation' => 'sometimes|required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données de l\'établissement ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $professionnel = $etablissement->professionnel;

            if ($professionnel) {
                $professionnelData = [];

                if ($request->has('nom')) {
                    $professionnelData['nom'] = html_entity_decode($request->nom);
                }

                if ($request->has('prenoms')) {
                    $professionnelData['prenoms'] = html_entity_decode($request->prenoms);
                }

                if ($request->has('mobile')) {
                    $professionnelData['mobile'] = html_entity_decode($request->mobile);
                }

                if (!empty($professionnelData)) {
                    $professionnel->update($professionnelData);
                }
            }

            $etablissementData = [];

            foreach ([
                'name',
                'adresse',
                'adresse_map',
                'longitude',
                'latitude',
                'pays_id',
                'ville_id',
                'commune_id',
                'type_etablissement_id',
                'specialite',
                'service_mobile',
                'description',
                'is_whatsapp',
                'mobile_fix',
            ] as $field) {
                if ($request->has($field)) {
                    $value = $request->$field;
                    $etablissementData[$field] = is_string($value) ? html_entity_decode($value) : $value;
                }
            }

            if ($request->has('email')) {
                $etablissementData['email'] = $request->filled('email') && trim((string) $request->email) !== ''
                    ? html_entity_decode($request->email)
                    : null;
            }

            if ($request->has('mobile')) {
                $etablissementData['mobile'] = html_entity_decode($request->mobile);
            }

            if ($request->has('type_etablissement_id')) {
                $etablissementData['categorie_service_id'] = $this->categorieServiceForTypeEtablissement(
                    (int) $request->type_etablissement_id
                );
            }

            if ($request->has('type_de_prestation')) {
                $etablissementData['type_de_prestations'] = $this->formatTypeDePrestation($request->type_de_prestation);
            }

            if ($request->hasFile('logo')) {
                $this->deleteEtablissementMedia($etablissement->logo, 'etablissement/logo');

                $etablissementData['logo'] = $this->wasabiService->uploadFile(
                    $request->file('logo'),
                    'etablissement/logo',
                    'logo'
                );
                $etablissementData['logo_create_by'] = 2;
            }

            if ($request->hasFile('cover')) {
                $this->deleteEtablissementMedia($etablissement->cover, 'etablissement/cover');

                $etablissementData['cover'] = $this->wasabiService->uploadFile(
                    $request->file('cover'),
                    'etablissement/cover',
                    'cover'
                );
                $etablissementData['cover_create_by'] = 2;
            }

            if (!empty($etablissementData)) {
                $etablissement->update($etablissementData);
            }

            DB::commit();

            $etablissement->load([
                'professionnel',
                'pays',
                'ville',
                'commune',
                'type_etablissement',
                'categorie_service',
                'abonnement_pros' => function ($query) {
                    $query->latest();
                },
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Établissement mis à jour avec succès.',
                'data' => $this->attachEtablissementMediaUrls($etablissement),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la mise à jour de l'établissement.",
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function attachEtablissementMediaUrls($etablissement)
    {
        if (!$etablissement) {
            return $etablissement;
        }

        foreach (['logo' => 'etablissement/logo', 'cover' => 'etablissement/cover'] as $field => $directory) {
            if (empty($etablissement->$field)) {
                continue;
            }

            $path = $this->normalizeEtablissementMediaPath($etablissement->$field, $directory);

            try {
                $etablissement->$field = $this->wasabiService->temporaryUrl($path) ?? $path;
            } catch (\Throwable $e) {
                $etablissement->$field = $path;
            }
        }

        return $etablissement;
    }

    protected function normalizeEtablissementMediaPath($value, $directory)
    {
        if (empty($value) || filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        if (Str::contains($value, '/')) {
            return ltrim($value, '/');
        }

        return trim($directory, '/') . '/' . ltrim($value, '/');
    }

    public function storePaiement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'referenceNumber' => 'nullable|string|unique:paiements,referenceNumber',
            'amount' => 'required|numeric',
            'description' => 'nullable|string',
            'countryCurrencyCode' => 'nullable|string',
            'customerEmail' => 'nullable|email',
            'customerFirstName' => 'required|string',
            'customerLastname' => 'required|string',
            'customerPhoneNumber' => 'required|string',
            'professionnel_id' => 'required|exists:professionnels,id',
            'forfait_pro_id' => 'required|exists:forfait_pros,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $referenceNumber = $request->referenceNumber ?: $this->generatePaymentReference('PRO');

        try {
            $paiement = Paiement::create([
                'referenceNumber' => $referenceNumber,
                'amount' => $request->amount,
                'description' => $request->description,
                'countryCurrencyCode' => $request->countryCurrencyCode,
                'customerEmail' => $request->customerEmail,
                'customerFirstName' => $request->customerFirstName,
                'customerLastname' => $request->customerLastname,
                'customerPhoneNumber' => $request->customerPhoneNumber,
                'professionnel_id' => $request->professionnel_id,
                'forfait_pro_id' => $request->forfait_pro_id,
                'statut' => 'en_attente'
            ]);

            return $this->createFineoPayCheckoutResponse($paiement, $request->description ?: 'Paiement abonnement etablissement TOO AUTO');
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'initialisation du paiement',
                'error' => $e->getMessage()
            ], 500)->header('Content-Type', 'application/json');
        }
    }

    public function verifierStatutPaiementApi(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reference' => 'required|string',
            'forfait_pro_id' => 'required|integer|exists:forfait_pros,id',
            'professionnel_id' => 'required|integer|exists:professionnels,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors()
            ], 422);
        }

        $forfaitPro = Forfait_pro::findOrFail($request->forfait_pro_id);
        $paiement = Paiement::where([
            'referenceNumber' => $request->reference,
            'amount' => intval($forfaitPro->prix),
            'professionnel_id' => $request->professionnel_id,
            'forfait_pro_id' => $request->forfait_pro_id,
        ])->first();

        return $this->paymentStatusResponse($paiement, 'pro');
    }

    public function storePaiementUsager(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'referenceNumber' => 'nullable|string|unique:paiements,referenceNumber',
            'amount' => 'required|numeric',
            'description' => 'nullable|string',
            'countryCurrencyCode' => 'nullable|string',
            'customerEmail' => 'nullable|email',
            'customerFirstName' => 'required|string',
            'customerLastname' => 'required|string',
            'customerPhoneNumber' => 'required|string',
            'user_id' => 'required|exists:users,id',
            'forfait_id' => 'required|exists:forfait_usagers,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $referenceNumber = $request->referenceNumber ?: $this->generatePaymentReference('USR');

        try {
            $paiement = Paiement::create([
                'referenceNumber' => $referenceNumber,
                'amount' => $request->amount,
                'description' => $request->description,
                'countryCurrencyCode' => $request->countryCurrencyCode,
                'customerEmail' => $request->customerEmail,
                'customerFirstName' => $request->customerFirstName,
                'customerLastname' => $request->customerLastname,
                'customerPhoneNumber' => $request->customerPhoneNumber,
                'user_id' => $request->user_id,
                'forfait_id' => $request->forfait_id,
                'statut' => 'en_attente'
            ]);

            return $this->createFineoPayCheckoutResponse($paiement, $request->description ?: 'Paiement abonnement usager TOO AUTO');
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'initialisation du paiement',
                'error' => $e->getMessage()
            ], 500)->header('Content-Type', 'application/json');
        }
    }

    public function verifierStatutPaiementApiUsager(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reference' => 'required|string',
            'forfait_id' => 'required|integer|exists:forfait_usagers,id',
            'user_id' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors()
            ], 422);
        }

        $forfait = Forfait::findOrFail($request->forfait_id);
        $paiement = Paiement::where([
            'referenceNumber' => $request->reference,
            'amount' => intval($forfait->prix),
            'user_id' => $request->user_id,
            'forfait_id' => $request->forfait_id,
        ])->first();

        return $this->paymentStatusResponse($paiement, 'usager');
    }

    public function fineoPayCallback(Request $request): JsonResponse
    {
        $callbackToken = $request->header('X-Callback-Token')
            ?: $request->header('X-FineoPay-Token')
            ?: $request->query('token');

        if (!$this->fineoPayService->isValidCallbackToken($callbackToken)) {
            Log::warning('Callback FineoPay rejete : token invalide.', [
                'syncRef' => $request->input('syncRef'),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Token callback invalide.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'syncRef' => 'required|string',
            'reference' => 'required|string',
            'amount' => 'required|numeric',
            'status' => 'required|string',
            'clientAccountNumber' => 'nullable|string',
            'timestamp' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les donnees du callback sont invalides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $paiement = Paiement::where('referenceNumber', $validated['syncRef'])->first();

        if (!$paiement) {
            Log::warning('Callback FineoPay recu pour un paiement introuvable.', [
                'syncRef' => $validated['syncRef'],
                'reference' => $validated['reference'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Paiement introuvable.',
            ], 404);
        }

        if ((int) $paiement->amount !== (int) $validated['amount']) {
            Log::warning('Callback FineoPay avec montant incorrect.', [
                'syncRef' => $validated['syncRef'],
                'expected_amount' => $paiement->amount,
                'received_amount' => $validated['amount'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Montant de paiement incorrect.',
            ], 422);
        }

        if ($paiement->statut === 'success') {
            return response()->json([
                'success' => true,
                'message' => 'Paiement deja traite.',
            ]);
        }

        if (strtolower($validated['status']) !== 'success') {
            $paiement->forceFill([
                'statut' => 'failed',
                'fineopay_reference' => $validated['reference'],
                'reponse_api' => [
                    'provider' => 'fineopay',
                    'callback' => $validated,
                ],
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Callback FineoPay recu. Paiement marque comme echoue.',
            ]);
        }

        return $this->activateSubscriptionFromFineoPayCallback($paiement, $validated);
    }

    private function createFineoPayCheckoutResponse(Paiement $paiement, string $title): JsonResponse
    {
        $checkoutPayload = [
            'title' => $title,
            'amount' => (int) $paiement->amount,
            'callbackUrl' => $this->fineoPayService->callbackUrl(),
            'syncRef' => $paiement->referenceNumber,
            'inputs' => [],
        ];

        $fineoPayRequest = [
            'url' => $this->fineoPayService->checkoutUrl(),
            'headers' => [
                'Content-Type' => 'application/json',
                'businessCode' => config('services.fineopay.business_code'),
                'apiKey' => config('services.fineopay.api_key') ? '***' : null,
            ],
            'payload' => $checkoutPayload,
        ];

        $fineoPayResponse = $this->fineoPayService->createCheckoutLink($checkoutPayload);
        $fineoPayBody = data_get($fineoPayResponse, 'body');
        $fineoPayHttpStatus = data_get($fineoPayResponse, 'http_status', 500);
        $checkoutLink = data_get($fineoPayBody, 'data.checkoutLink');

        if (!$checkoutLink) {
            $paiement->forceFill([
                'statut' => 'failed',
                'reponse_api' => [
                    'provider' => 'fineopay',
                    'request' => $fineoPayRequest,
                    'response' => $fineoPayResponse,
                ],
            ])->save();

            return response()->json([
                'status' => 'error',
                'message' => 'FineoPay n\'a pas retourne de lien de paiement.',
                'fineopay_request' => $fineoPayRequest,
                'fineopay' => $fineoPayBody,
                'http_status' => $fineoPayHttpStatus,
            ], $fineoPayHttpStatus >= 400 && $fineoPayHttpStatus < 600 ? $fineoPayHttpStatus : 502);
        }

        $paiement->forceFill([
            'checkout_link' => $checkoutLink,
            'reponse_api' => [
                'provider' => 'fineopay',
                'request' => $fineoPayRequest,
                'checkout' => $fineoPayResponse,
            ],
        ])->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Lien de paiement genere avec succes',
            'data' => [
                'paiement' => $paiement,
                'checkoutLink' => $checkoutLink,
                'syncRef' => $paiement->referenceNumber,
                'fineopay_request' => $fineoPayRequest,
            ],
        ], 201)->header('Content-Type', 'application/json');
    }

    private function paymentStatusResponse(?Paiement $paiement, string $type): JsonResponse
    {
        if (!$paiement) {
            return response()->json([
                'success' => false,
                'message' => 'Paiement introuvable.',
            ], 404);
        }

        $abonnement = null;

        if ($paiement->statut === 'success') {
            if ($type === 'pro') {
                $abonnement = Abonnement_pro::where('etablissement_id', optional(Etablissement::where('professionnel_id', $paiement->professionnel_id)->first())->id)
                    ->where('forfait_pro_id', $paiement->forfait_pro_id)
                    ->latest()
                    ->first();
            } else {
                $abonnement = AbonnementUsager::where('user_id', $paiement->user_id)
                    ->where('forfait_id', $paiement->forfait_id)
                    ->latest()
                    ->first();
            }
        }

        $messages = [
            'en_attente' => 'Paiement en attente de confirmation.',
            'success' => 'Paiement confirme. Abonnement active.',
            'failed' => 'Paiement echoue.',
        ];

        return response()->json([
            'success' => true,
            'status' => $paiement->statut,
            'message' => $messages[$paiement->statut] ?? 'Statut du paiement recupere.',
            'data' => [
                'paiement' => $paiement,
                'abonnement' => $abonnement,
            ],
        ]);
    }

    private function activateSubscriptionFromFineoPayCallback(Paiement $paiement, array $callbackData): JsonResponse
    {
        DB::beginTransaction();

        try {
            $dateDebut = now();

            if ($paiement->professionnel_id && $paiement->forfait_pro_id) {
                $forfaitPro = Forfait_pro::findOrFail($paiement->forfait_pro_id);
                $etablissement = Etablissement::where('professionnel_id', $paiement->professionnel_id)->first();

                if (!$etablissement) {
                    throw new \Exception('Aucun etablissement trouve pour ce professionnel.');
                }

                $dateFin = now()->addDays((int) $forfaitPro->duree);

                $abonnement = Abonnement_pro::create([
                    'etablissement_id' => $etablissement->id,
                    'professionnel_id' => $paiement->professionnel_id,
                    'forfait_pro_id' => $paiement->forfait_pro_id,
                    'date_debut' => $dateDebut,
                    'date_fin' => $dateFin,
                ]);
            } elseif ($paiement->user_id && $paiement->forfait_id) {
                $forfait = Forfait::findOrFail($paiement->forfait_id);
                $dateFin = now()->addDays((int) $forfait->duree);

                $abonnement = AbonnementUsager::create([
                    'user_id' => $paiement->user_id,
                    'forfait_id' => $paiement->forfait_id,
                    'date_debut' => $dateDebut,
                    'date_fin' => $dateFin,
                ]);

                app(CommercialWalletService::class)->creditCommissionForAbonnement($abonnement);
            } else {
                throw new \Exception('Type de paiement non reconnu.');
            }

            $paiement->forceFill([
                'statut' => 'success',
                'fineopay_reference' => $callbackData['reference'],
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'reponse_api' => [
                    'provider' => 'fineopay',
                    'callback' => $callbackData,
                ],
            ])->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paiement FineoPay confirme. Abonnement active.',
                'data' => [
                    'paiement' => $paiement,
                    'abonnement' => $abonnement,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Erreur lors du traitement du callback FineoPay.', [
                'syncRef' => $callbackData['syncRef'] ?? null,
                'reference' => $callbackData['reference'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement du callback FineoPay.',
            ], 500);
        }
    }

    private function commercialApiPayload(Commercial $commercial): array
    {
        $payload = $commercial->toArray();
        unset($payload['password'], $payload['remember_token']);
        $payload['files'] = $this->commercialSignedFiles($commercial);

        return $payload;
    }

    private function commercialSignedFiles(Commercial $commercial): array
    {
        $files = [];

        foreach (['piece_recto', 'piece_verso', 'photo'] as $field) {
            $path = $commercial->{$field} ?? null;
            $url = null;

            if ($path) {
                try {
                    $url = $this->wasabiService->temporaryUrl($path) ?? $path;
                } catch (\Throwable $e) {
                    $url = $path;
                }
            }

            $files[$field] = [
                'path' => $path,
                'url' => $url,
            ];
        }

        return $files;
    }

    private function commercialWalletPayload(Commercial $commercial, int $transactionsLimit = 5): array
    {
        $wallet = $commercial->wallet ?: CommercialWallet::firstOrCreate(
            ['commercial_id' => $commercial->id],
            ['balance' => 0]
        );

        $transactionsQuery = CommercialWalletTransaction::where('commercial_id', $commercial->id);

        return [
            'id' => $wallet->id,
            'commercial_id' => $commercial->id,
            'balance' => (float) $wallet->balance,
            'total_credit' => (float) (clone $transactionsQuery)->where('direction', 'credit')->sum('amount'),
            'total_debit' => (float) (clone $transactionsQuery)->where('direction', 'debit')->sum('amount'),
            'transactions' => (clone $transactionsQuery)
                ->orderBy('id', 'desc')
                ->limit($transactionsLimit)
                ->get(),
        ];
    }
    private function generatePaymentReference(string $prefix): string
    {
        return 'TOO-' . $prefix . '-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
    }

    // Afficher un prospecteur spécifique
    public function showProspector($id)
    {
        $prospector = Etablissement::find($id);

        if (!$prospector) {
            return response()->json(['message' => 'Prospecteur non trouvé.'], 404);
        }

        return response()->json($this->attachEtablissementMediaUrls($prospector), 200);
    }

    // Mettre à jour un prospecteur
    public function updateProspector(Request $request, $id)
    {
        $prospector = Etablissement::find($id);

        if (!$prospector) {
            return response()->json(['message' => 'Prospecteur non trouvé.'], 404);
        }

        $validatedData = $request->validate([
            'nom_etablissement' => 'string|max:200',
            'name_gerant' => 'string|max:200',
            'name_responsable_commercial' => 'string|max:200',
            'mobile' => 'string|max:20',
            'email' => 'nullable|email|max:200',
            'type_etablissement_id' => 'integer',
            'adresse' => 'string|max:300',
            'longitude' => 'string|max:20',
            'latitude' => 'string|max:20',
            'commercial_id' => 'integer',
            'agree' => 'integer',
        ]);

        $prospector->update($validatedData);

        if($prospector){
			return response()->json([
				'success' => true,
				'message' => "Établissement mis a jour avec succès",
				'data' => $this->attachEtablissementMediaUrls($prospector),
			], 201);
		}else{
			return response()->json([
				'success' => false,
				'message' => "Échec de la mise a jour de l'établissement",
			], 401);
		}
    }

        /**
     * Display a listing of the resource.
     */
    public function getTypeEtablissement()
    {
        // Récupérer les établissements triés par ID décroissant
        $type_etablissements = Type_etablissement::orderBy('id', 'desc')->get();

        // Vérifier si des établissements existent
        if ($type_etablissements->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => "Aucun type d'établissement enregistré pour le moment.",
            ], 404);
        }

        // Retourner la liste des établissements
        return response()->json([
            'success' => true,
            'message' => "Liste des type d'établissements.",
            'type_etablissements' => $type_etablissements,
        ], 200);
    }

	public function registerUsager(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'indicatif' => 'required|string',
			'nom' => 'required|string',
			'prenoms' => 'required|string',
            'mobile' => 'required|numeric|unique:users',
            'is_whatsapp' => 'required|numeric',
            'immatriculation' => 'nullable|string|unique:vehicules,matricule',
            'carte_grise' => 'nullable|string|unique:vehicules,carte_grise',
            'photos' => 'nullable|array|size:4',
            'photos.*' => 'file|image|max:25048',
            'type_de_vehicule_id' => 'nullable|exists:type_de_vehicules,id',
            'marque_id' => 'nullable|exists:marques,id',
            'type_de_carburant_id' => 'nullable|exists:type_de_carburants,id',
            'couleur' => 'nullable|string|max:50',
            'modele' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Utilisation d'une transaction pour garantir l'intégrité des données
        DB::beginTransaction();
        try {

			$rawPassword = strval(random_int(100000, 999999));

			$commercial = auth()->user();

            // Création de l'utilisateur
            $user = new User();
            $user->uuid = (string) Str::uuid();
            $user->indicatif = $request->indicatif;
            $user->mobile = $request->mobile;
            $user->nom = $request->nom;
            $user->prenoms = $request->prenoms;
            $user->password = bcrypt($rawPassword); // Hash sécurisé du mot de passe
			$user->is_whatsapp = $request->is_whatsapp;
			$user->commercial_id = $commercial->id;

            $user->save();

            $vehicule = null;

            if ($request->filled('immatriculation')) {
                $vehicule = new Vehicule();
                $vehicule->matricule = $request->immatriculation;
                $vehicule->carte_grise = $request->carte_grise;
                $vehicule->type_de_vehicule_id = $request->type_de_vehicule_id;
                $vehicule->marque_id = $request->marque_id;
                $vehicule->type_de_carburant_id = $request->type_de_carburant_id;
                $vehicule->couleur = $request->couleur;
                $vehicule->modele = $request->modele;
                $vehicule->user_id = $user->id;
                $vehicule->created_by = $commercial->id;
                $vehicule->provenance = "commerciaux";

                if ($request->hasFile('photos')) {
                    $photosPaths = [];

                    foreach ($request->file('photos') as $photo) {
                        $photosPaths[] = $this->wasabiService->uploadFile(
                            $photo,
                            'vehicules/photos',
                            'vehicule'
                        );
                    }

                    $vehicule->photos = json_encode($photosPaths);
                }

                $vehicule->save();
            }

            // Commit de la transaction
            DB::commit();

			$mobileWithIndicatif = $request->indicatif . $request->mobile;
			$password = $rawPassword;

			// Construire le message
			$message = strtoupper(
				"Votre compte a ete cree avec succes\n" .
				"Voici vos identifiants de connexion :\n" .
				"Numero de telephone : $mobileWithIndicatif\n" .
				"Mot de passe : $password"
			);


			// Envoyer le SMS
            $smsResponse = $this->sendMessageConfirmOrder($message, $mobileWithIndicatif);

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur enregistré avec succès.',
                'user' => $user,
                'vehicule' => $vehicule ? $this->attachVehiculePhotoUrls($vehicule) : null,
            ], 201); // Utilisation du code HTTP 201 pour "Created"

        } catch (\Exception $e) {
            // Rollback de la transaction en cas d'erreur
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'enregistrement de l'utilisateur.",
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

	public function getUsagerByCommercial()
	{
		try {
			$commercial = auth()->user();

			// Vérifier si le commercial est bien authentifié
			if (!$commercial) {
				return response()->json([
					'success' => false,
					'message' => "Commercial non trouvé.",
				], 404);
			}

			// Récupérer les usagers associés au commercial avec leurs véhicules
			$usagers = User::with('vehicules')
				->where('commercial_id', $commercial->id)
				->get();

			// Vérifier s'il y a des usagers
			if ($usagers->isEmpty()) {
				return response()->json([
					'success' => false,
					'message' => "Aucun usager trouvé.",
				], 404);
			}

			$usagers->each(function ($usager) {
				$usager->vehicules->transform(function ($vehicule) {
					return $this->attachVehiculePhotoUrls($vehicule);
				});
			});

			return response()->json([
				'success' => true,
				'data' => $usagers,
			], 200);

		} catch (\Exception $e) {
			// Gestion des erreurs
			return response()->json([
				'success' => false,
				'message' => "Une erreur est survenue lors de l'affichage.",
				'dev' => $e->getMessage(),
			], 500);
		}
	}

	 public function getPays(): JsonResponse
	{
		try {
			$pays = Pays::all();
			return response()->json([
				'success' => true,
				'data' => $pays,
			], 200);
		} catch (\Exception $e) {
			\Log::error('Erreur lors de la récupération des pays', ['message' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'message' => 'Une erreur est survenue lors de la récupération des pays.',
			], 500);
		}
	}

	public function getVille(): JsonResponse
	{
		try {
			$villes = Ville::all();
			return response()->json([
				'success' => true,
				'data' => $villes,
			], 200);
		} catch (\Exception $e) {
			\Log::error('Erreur lors de la récupération des villes', ['message' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'message' => 'Une erreur est survenue lors de la récupération des villes.',
			], 500);
		}
	}

	public function getCommune(): JsonResponse
	{
		try {
			$communes = Commune::all();
			return response()->json([
				'success' => true,
				'data' => $communes,
			], 200);
		} catch (\Exception $e) {
			\Log::error('Erreur lors de la récupération des communes', ['message' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'message' => 'Une erreur est survenue lors de la récupération des communes.',
			], 500);
		}
	}

	public function getTypeDePrestation(): JsonResponse
	{
		try {
			$types = Type_de_prestation::all();
			return response()->json([
				'success' => true,
				'data' => $types,
			], 200);
		} catch (\Exception $e) {
			\Log::error('Erreur lors de la récupération des types de prestation', ['message' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'message' => 'Une erreur est survenue lors de la récupération des types de prestation.',
			], 500);
		}
	}

	public function getForfaitPro(): JsonResponse
	{
		try {
			$types = Forfait_pro::all();
			return response()->json([
				'success' => true,
				'data' => $types,
			], 200);
		} catch (\Exception $e) {
			\Log::error('Erreur lors de la récupération des forfaits', ['message' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'message' => 'Une erreur est survenue lors de la récupération des forfaits.',
			], 500);
		}
	}

	public function getForfaitUsager(): JsonResponse
	{
		try {
			$types = Forfait::all();
			return response()->json([
				'success' => true,
				'data' => $types,
			], 200);
		} catch (\Exception $e) {
			\Log::error('Erreur lors de la récupération des forfaits', ['message' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'message' => 'Une erreur est survenue lors de la récupération des forfaits.',
			], 500);
		}
	}

    public function storeStationDeLavageWithAccount(Request $request): JsonResponse
    {
        $this->logStationLogoUploadState($request, 'station_de_lavages.register.received');

        $logoUploadError = $this->stationLogoUploadErrorResponse($request);

        if ($logoUploadError) {
            Log::error('Logo station de lavage rejete avant validation', $this->stationLogoUploadDiagnostics($request));
            return $logoUploadError;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'contact' => 'required|string|max:20',
            'longitude' => 'nullable|string|max:20',
            'latitude' => 'nullable|string|max:20',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'mobile' => 'required|string|max:20|unique:lavages,mobile',
            'email' => 'nullable|email|max:100|unique:lavages,email',
            'password' => 'nullable|string|min:6|max:100',
            'role' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $commercial = auth()->user();

        if (!$commercial) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable.',
            ], 401);
        }

        $logoPath = null;
        $plainPassword = $request->filled('password')
            ? (string) $request->password
            : (string) random_int(100000, 999999);

        DB::beginTransaction();

        try {
            if ($request->hasFile('logo')) {
                Log::info('Upload logo station de lavage vers Wasabi', $this->stationLogoUploadDiagnostics($request));

                $logoPath = $this->wasabiService->uploadFile(
                    $request->file('logo'),
                    'station_de_lavages/logos',
                    'station-lavage'
                );

                Log::info('Logo station de lavage enregistre sur Wasabi', [
                    'path' => $logoPath,
                ]);
            } else {
                Log::warning('Aucun fichier logo recu pour la station de lavage', $this->stationLogoUploadDiagnostics($request));
            }

            $lavage = Lavage::create([
                'first_name' => html_entity_decode($request->first_name),
                'last_name' => html_entity_decode($request->last_name),
                'mobile' => html_entity_decode($request->mobile),
                'email' => $request->filled('email') ? html_entity_decode($request->email) : null,
                'password' => Hash::make($plainPassword),
                'role' => $request->filled('role') ? (int) $request->role : 1,
                'statut' => 1,
                'created_by' => $commercial->id,
            ]);

            $stationDeLavage = StationDeLavage::create([
                'name' => html_entity_decode($request->name),
                'adresse' => $request->filled('adresse') ? html_entity_decode($request->adresse) : null,
                'contact' => html_entity_decode($request->contact),
                'longitude' => $request->longitude,
                'latitude' => $request->latitude,
                'logo' => $logoPath,
                'statut' => 1,
                'created_by' => $lavage->id,
            ]);

            DB::commit();

            $smsSent = false;
            $smsError = null;

            try {
                $message = strtoupper(
                    "Votre compte lavage TOOAUTO a ete cree avec succes\n" .
                    "Station : " . $stationDeLavage->name . "\n" .
                    "Numero : " . $lavage->mobile . "\n" .
                    "Mot de passe : " . $plainPassword
                );

                $this->sendMessageConfirmOrder($message, $lavage->mobile);
                $smsSent = true;
            } catch (\Exception $e) {
                $smsError = $e->getMessage();
                Log::error('Erreur SMS creation station de lavage', [
                    'lavage_id' => $lavage->id,
                    'station_de_lavage_id' => $stationDeLavage->id,
                    'message' => $smsError,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => $smsSent
                    ? 'Station de lavage et compte lavage créés avec succès. Les accès ont été envoyés par SMS.'
                    : 'Station de lavage et compte lavage créés avec succès, mais le SMS n\'a pas pu être envoyé.',
                'data' => [
                    'station_de_lavage' => $this->attachStationDeLavageLogoUrl($stationDeLavage->refresh(), $logoPath),
                    'lavage' => $lavage,
                    'access_mobile' => $lavage->mobile,
                    'sms_sent' => $smsSent,
                    'sms_error' => $smsSent ? null : $smsError,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            if ($logoPath) {
                try {
                    $this->wasabiService->deleteFile($logoPath);
                } catch (\Throwable $deleteError) {
                    Log::error('Erreur suppression logo station de lavage apres rollback', [
                        'path' => $logoPath,
                        'message' => $deleteError->getMessage(),
                    ]);
                }
            }

            Log::error('Erreur creation station de lavage avec compte', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la création de la station de lavage.',
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateStationDeLavageWithAccount(Request $request, $stationDeLavageId, $lavageId): JsonResponse
    {
        $this->logStationLogoUploadState($request, 'station_de_lavages.update.received');

        $logoUploadError = $this->stationLogoUploadErrorResponse($request);

        if ($logoUploadError) {
            Log::error('Logo station de lavage rejete avant update', $this->stationLogoUploadDiagnostics($request));
            return $logoUploadError;
        }

        $commercial = auth()->user();

        if (!$commercial) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable.',
            ], 401);
        }

        $lavage = Lavage::where('created_by', $commercial->id)->find($lavageId);
        $stationDeLavage = $lavage
            ? StationDeLavage::where('created_by', $lavage->id)->find($stationDeLavageId)
            : null;

        if (!$stationDeLavage || !$lavage) {
            return response()->json([
                'success' => false,
                'message' => 'Station de lavage ou compte lavage introuvable.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'contact' => 'sometimes|required|string|max:20',
            'longitude' => 'nullable|string|max:20',
            'latitude' => 'nullable|string|max:20',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'mobile' => 'sometimes|required|string|max:20|unique:lavages,mobile,' . $lavage->id,
            'email' => 'nullable|email|max:100|unique:lavages,email,' . $lavage->id,
            'password' => 'nullable|string|min:6|max:100',
            'role' => 'nullable|integer',
            'statut' => 'sometimes|required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données fournies ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $plainPassword = $request->filled('password') ? (string) $request->password : null;
        $logoPath = $stationDeLavage->logo;

        DB::beginTransaction();

        try {
            $stationData = [];

            foreach (['name', 'adresse', 'contact', 'longitude', 'latitude', 'statut'] as $field) {
                if ($request->has($field)) {
                    $value = $request->$field;
                    $stationData[$field] = is_string($value) ? html_entity_decode($value) : $value;
                }
            }

            if ($request->hasFile('logo')) {
                Log::info('Upload logo station de lavage update vers Wasabi', $this->stationLogoUploadDiagnostics($request));
                $this->wasabiService->deleteFile($stationDeLavage->logo);
                $logoPath = $this->wasabiService->uploadFile(
                    $request->file('logo'),
                    'station_de_lavages/logos',
                    'station-lavage'
                );
                $stationData['logo'] = $logoPath;
                Log::info('Logo station de lavage update enregistre sur Wasabi', ['path' => $logoPath]);
            }

            if (!empty($stationData)) {
                $stationDeLavage->update($stationData);
            }

            $lavageData = [];

            foreach (['first_name', 'last_name', 'mobile', 'email', 'role', 'statut'] as $field) {
                if ($request->has($field)) {
                    $value = $request->$field;
                    $lavageData[$field] = is_string($value) ? html_entity_decode($value) : $value;
                }
            }

            if (array_key_exists('email', $lavageData)) {
                $lavageData['email'] = $request->filled('email') ? html_entity_decode($request->email) : null;
            }

            if ($plainPassword) {
                $lavageData['password'] = Hash::make($plainPassword);
            }

            if (!empty($lavageData)) {
                $lavage->update($lavageData);
            }

            DB::commit();

            $smsSent = false;
            $smsError = null;

            if ($plainPassword) {
                try {
                    $message = strtoupper(
                        "Vos acces lavage TOOAUTO ont ete mis a jour\n" .
                        "Numero : " . $lavage->fresh()->mobile . "\n" .
                        "Mot de passe : " . $plainPassword
                    );
                    $this->sendMessageConfirmOrder($message, $lavage->fresh()->mobile);
                    $smsSent = true;
                } catch (\Exception $e) {
                    $smsError = $e->getMessage();
                    Log::error('Erreur SMS update station de lavage', [
                        'lavage_id' => $lavage->id,
                        'station_de_lavage_id' => $stationDeLavage->id,
                        'message' => $smsError,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Station de lavage et compte lavage mis à jour avec succès.',
                'data' => [
                    'station_de_lavage' => $this->attachStationDeLavageLogoUrl($stationDeLavage->refresh(), $logoPath),
                    'lavage' => $lavage->refresh(),
                    'sms_sent' => $smsSent,
                    'sms_error' => $smsSent ? null : $smsError,
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Erreur update station de lavage avec compte', [
                'station_de_lavage_id' => $stationDeLavage->id,
                'lavage_id' => $lavage->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour de la station de lavage.',
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

    public function indexStationService(): JsonResponse
    {
        try {
            $commercial = auth()->user();

            if (!$commercial) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur introuvable.',
                ], 401);
            }

            $stationServices = StationService::with(['ville', 'commune', 'stationAccount'])
                ->where('created_by', $commercial->id)
                ->latest()
                ->get()
                ->map(function ($stationService) {
                    return $this->formatStationServiceForResponse($stationService);
                });

            return response()->json([
                'success' => true,
                'data' => $stationServices,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des stations services', ['message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des stations services.',
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

    public function indexStationDeLavageWithAccounts(): JsonResponse
    {
        try {
            $commercial = auth()->user();

            if (!$commercial) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur introuvable.',
                ], 401);
            }

            $lavages = Lavage::with('stationDeLavage')
                ->where('created_by', $commercial->id)
                ->latest()
                ->get()
                ->map(function ($lavage) {
                    if ($lavage->stationDeLavage) {
                        $lavage->setRelation(
                            'stationDeLavage',
                            $this->attachStationDeLavageLogoUrl($lavage->stationDeLavage)
                        );
                    }

                    return $lavage;
                });
            $stationsDeLavage = $lavages->pluck('stationDeLavage')->filter()->values();

            return response()->json([
                'success' => true,
                'message' => 'Liste des lavages et stations de lavage récupérée avec succès.',
                'data' => [
                    'lavages' => $lavages,
                    'stations_de_lavage' => $stationsDeLavage,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur index station de lavage avec comptes', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des stations de lavage.',
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

    public function storeStationService(Request $request): JsonResponse
    {
        $this->logStationLogoUploadState($request, 'station_services.store.received');

        $logoUploadError = $this->stationLogoUploadErrorResponse($request);

        if ($logoUploadError) {
            Log::error('Logo station service rejete avant validation', $this->stationLogoUploadDiagnostics($request));
            return $logoUploadError;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:300',
            'ville_id' => 'nullable|integer|exists:villes,id',
            'commune_id' => 'nullable|integer|exists:communes,id',
            'email' => 'nullable|email|max:200|unique:station_services,email',
            'mobile' => 'nullable|string|max:20',
            'adresse' => 'nullable|string|max:500',
            'longitude' => 'nullable|string|max:100',
            'latitude' => 'nullable|string|max:100',
            'adresse_map' => 'nullable|string|max:500',
            'borne_electrique' => 'nullable|integer|min:0',
            'statut' => 'sometimes|required|integer|in:0,1',
            'station_electrique' => 'sometimes|required|integer|in:0,1',
            'nuit' => 'sometimes|required|integer|in:0,1',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:8048',
            'station_first_name' => 'nullable|string|max:200',
            'station_last_name' => 'nullable|string|max:200',
            'station_mobile' => 'nullable|string|max:20|unique:stations,mobile',
            'station_email' => 'nullable|email|max:300|unique:stations,email',
            'station_password' => 'nullable|string|min:6|max:100',
            'station_role' => 'nullable|integer',
        ]);

        $validator->after(function ($validator) use ($request) {
            if ((int) $request->input('station_electrique', 0) === 1 && (int) $request->borne_electrique < 1) {
                $validator->errors()->add('borne_electrique', 'Le nombre de bornes électriques doit être supérieur ou égal à 1.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données de la station service ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $commercial = auth()->user();

        if (!$commercial) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable.',
            ], 401);
        }

        DB::beginTransaction();

        try {
            $data = $this->stationServicePayload($request);
            $data['created_by'] = $commercial->id;
            $data['statut'] = $request->has('statut') ? (int) $request->statut : 1;
            $data['station_electrique'] = (int) $request->input('station_electrique', 0);
            $data['borne_electrique'] = (int) $request->input('station_electrique', 0) === 1 ? (int) $request->borne_electrique : 0;
            $data['nuit'] = $request->has('nuit') ? (int) $request->nuit : 0;

            if ($request->hasFile('logo')) {
                Log::info('Upload logo station service vers Wasabi', $this->stationLogoUploadDiagnostics($request));

                $data['logo'] = $this->wasabiService->uploadFile(
                    $request->file('logo'),
                    'station_services/logo',
                    'logo'
                );

                Log::info('Logo station service enregistre sur Wasabi', [
                    'path' => $data['logo'],
                ]);
            } else {
                Log::warning('Aucun fichier logo recu pour la station service', $this->stationLogoUploadDiagnostics($request));
            }

            $stationService = StationService::create($data);
            $plainPassword = $request->filled('station_password')
                ? (string) $request->station_password
                : (string) random_int(100000, 999999);
            $stationAccount = $this->stationAccountPayload($request, $stationService, $commercial->id, $plainPassword);
            $canSendSms = $stationAccount['can_send_sms'];
            unset($stationAccount['can_send_sms']);

            $station = Station::create($stationAccount);

            DB::commit();

            $stationService->load(['ville', 'commune']);

            $smsSent = false;
            $smsError = null;

            try {
                $message = "Votre compte station TOOAUTO a ete cree. Numero: "
                    . $station->mobile
                    . " Mot de passe: " . $plainPassword;

                if ($canSendSms) {
                    $this->sendMessageConfirmOrder($message, $station->mobile);
                    $smsSent = true;
                }
            } catch (\Exception $e) {
                $smsError = $e->getMessage();
                Log::error('Erreur SMS creation station service', ['message' => $smsError]);
            }

            return response()->json([
                'success' => true,
                'message' => $smsSent
                    ? 'Station service enregistrée avec succès. Le mot de passe a été envoyé par SMS.'
                    : 'Station service enregistrée avec succès, mais le SMS n\'a pas pu être envoyé.',
                'data' => $this->formatStationServiceForResponse($stationService->refresh()->load(['ville', 'commune'])),
                'station' => $station,
                'sms_sent' => $smsSent,
                'sms_error' => $smsSent ? null : $smsError,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'enregistrement de la station service.",
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

    public function showStationService($id): JsonResponse
    {
        $stationService = $this->findStationServiceForCurrentUser($id);

        if (!$stationService) {
            return response()->json([
                'success' => false,
                'message' => 'Station service introuvable.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatStationServiceForResponse($stationService),
        ], 200);
    }

    public function updateStationService(Request $request, $id): JsonResponse
    {
        $this->logStationLogoUploadState($request, 'station_services.update.received');

        $logoUploadError = $this->stationLogoUploadErrorResponse($request);

        if ($logoUploadError) {
            Log::error('Logo station service rejete avant update', $this->stationLogoUploadDiagnostics($request));
            return $logoUploadError;
        }

        $stationService = $this->findStationServiceForCurrentUser($id);

        if (!$stationService) {
            return response()->json([
                'success' => false,
                'message' => 'Station service introuvable.',
            ], 404);
        }

        $station = Station::where('station_service_id', $stationService->id)->first();
        $stationId = $station ? $station->id : null;
        $stationMobileUniqueRule = 'nullable|string|max:20|unique:stations,mobile' . ($stationId ? ',' . $stationId : '');
        $stationEmailUniqueRule = 'nullable|email|max:300|unique:stations,email' . ($stationId ? ',' . $stationId : '');

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:300',
            'ville_id' => 'nullable|integer|exists:villes,id',
            'commune_id' => 'nullable|integer|exists:communes,id',
            'email' => 'nullable|email|max:200|unique:station_services,email,' . $stationService->id,
            'mobile' => 'nullable|string|max:20',
            'adresse' => 'nullable|string|max:500',
            'longitude' => 'nullable|string|max:100',
            'latitude' => 'nullable|string|max:100',
            'adresse_map' => 'nullable|string|max:500',
            'borne_electrique' => 'sometimes|required|integer|min:0',
            'statut' => 'sometimes|required|integer|in:0,1',
            'station_electrique' => 'sometimes|required|integer|in:0,1',
            'nuit' => 'sometimes|required|integer|in:0,1',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:8048',
            'station_first_name' => 'nullable|string|max:200',
            'station_last_name' => 'nullable|string|max:200',
            'station_mobile' => $stationMobileUniqueRule,
            'station_email' => $stationEmailUniqueRule,
            'station_password' => 'nullable|string|min:6|max:100',
            'station_role' => 'nullable|integer',
            'station_statut' => 'nullable|integer|in:0,1',
        ]);

        $validator->after(function ($validator) use ($request, $stationService) {
            $isElectric = $request->has('station_electrique')
                ? (int) $request->station_electrique === 1
                : (int) $stationService->station_electrique === 1;

            $borneElectrique = $request->has('borne_electrique')
                ? (int) $request->borne_electrique
                : (int) $stationService->borne_electrique;

            if ($isElectric && $borneElectrique < 1) {
                $validator->errors()->add('borne_electrique', 'Le nombre de bornes électriques doit être supérieur ou égal à 1.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Les données de la station service ne sont pas valides.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $data = $this->stationServicePayload($request);
            $plainPassword = $request->filled('station_password') ? (string) $request->station_password : null;

            if (array_key_exists('station_electrique', $data) && (int) $data['station_electrique'] === 0) {
                $data['borne_electrique'] = 0;
            }

            if ($request->hasFile('logo')) {
                Log::info('Upload logo station service update vers Wasabi', $this->stationLogoUploadDiagnostics($request));
                $this->wasabiService->deleteFile($stationService->logo);
                $data['logo'] = $this->wasabiService->uploadFile(
                    $request->file('logo'),
                    'station_services/logo',
                    'logo'
                );
                Log::info('Logo station service update enregistre sur Wasabi', [
                    'path' => $data['logo'],
                ]);
            }

            if (!empty($data)) {
                $stationService->update($data);
            }

            if ($station) {
                $stationData = [];

                $stationFieldMap = [
                    'station_first_name' => 'first_name',
                    'station_last_name' => 'last_name',
                    'station_mobile' => 'mobile',
                    'station_email' => 'email',
                    'station_role' => 'role',
                    'station_statut' => 'statut',
                ];

                foreach ($stationFieldMap as $requestField => $column) {
                    if ($request->has($requestField)) {
                        $value = $request->$requestField;
                        $stationData[$column] = is_string($value) ? html_entity_decode($value) : $value;
                    }
                }

                if (array_key_exists('email', $stationData)) {
                    $stationData['email'] = $request->filled('station_email') ? html_entity_decode($request->station_email) : null;
                }

                if ($plainPassword) {
                    $stationData['password'] = Hash::make($plainPassword);
                }

                if (!empty($stationData)) {
                    $station->update($stationData);
                }
            }

            DB::commit();

            $stationService->load(['ville', 'commune']);

            $smsSent = false;
            $smsError = null;

            if ($plainPassword && $station) {
                try {
                    $freshStation = $station->fresh();
                    $message = "Vos acces station TOOAUTO ont ete mis a jour. Numero: "
                        . $freshStation->mobile
                        . " Mot de passe: " . $plainPassword;

                    $this->sendMessageConfirmOrder($message, $freshStation->mobile);
                    $smsSent = true;
                } catch (\Exception $e) {
                    $smsError = $e->getMessage();
                    Log::error('Erreur SMS update station service', [
                        'station_service_id' => $stationService->id,
                        'station_id' => optional($station)->id,
                        'message' => $smsError,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Station service et compte station mis à jour avec succès.',
                'data' => $this->formatStationServiceForResponse($stationService->refresh()->load(['ville', 'commune'])),
                'station' => $station ? $station->refresh() : null,
                'sms_sent' => $smsSent,
                'sms_error' => $smsSent ? null : $smsError,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour de la station service.',
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteStationService($id): JsonResponse
    {
        $stationService = $this->findStationServiceForCurrentUser($id);

        if (!$stationService) {
            return response()->json([
                'success' => false,
                'message' => 'Station service introuvable.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $this->wasabiService->deleteFile($stationService->logo);
            $stationService->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Station service supprimée avec succès.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la suppression de la station service.',
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

    protected function stationServicePayload(Request $request): array
    {
        $data = [];

        foreach ([
            'name',
            'ville_id',
            'commune_id',
            'email',
            'mobile',
            'adresse',
            'longitude',
            'latitude',
            'adresse_map',
            'borne_electrique',
            'statut',
            'station_electrique',
            'nuit',
        ] as $field) {
            if ($request->has($field)) {
                $value = $request->$field;
                $data[$field] = is_string($value) ? html_entity_decode($value) : $value;
            }
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = $request->filled('email') && trim((string) $request->email) !== ''
                ? html_entity_decode($request->email)
                : null;
        }

        foreach (['ville_id', 'commune_id', 'borne_electrique', 'statut', 'station_electrique', 'nuit'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = (int) $data[$field];
            }
        }

        return $data;
    }

    protected function stationAccountPayload(Request $request, StationService $stationService, int $commercialId, string $plainPassword): array
    {
        $stationName = trim((string) $stationService->name);
        $nameParts = preg_split('/\s+/', $stationName, 2);

        $firstName = $request->filled('station_first_name')
            ? html_entity_decode($request->station_first_name)
            : ($nameParts[0] ?? 'Station');

        $lastName = $request->filled('station_last_name')
            ? html_entity_decode($request->station_last_name)
            : ($nameParts[1] ?? 'Service');

        $mobile = null;
        $canSendSms = false;

        if ($request->filled('station_mobile')) {
            $mobile = html_entity_decode($request->station_mobile);
            $canSendSms = true;
        } elseif (!empty($stationService->mobile) && !Station::where('mobile', $stationService->mobile)->exists()) {
            $mobile = $stationService->mobile;
            $canSendSms = true;
        } else {
            $mobile = $this->generateStationMobile();
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'mobile' => $mobile,
            'email' => $request->filled('station_email') ? html_entity_decode($request->station_email) : null,
            'password' => Hash::make($plainPassword),
            'statut' => 1,
            'role' => $request->station_role,
            'created_by' => $commercialId,
            'station_service_id' => $stationService->id,
            'can_send_sms' => $canSendSms,
        ];
    }

    protected function generateStationMobile(): string
    {
        do {
            $mobile = 'ST' . now()->format('ymdHis') . random_int(10, 99);
        } while (Station::where('mobile', $mobile)->exists());

        return $mobile;
    }

    protected function findStationServiceForCurrentUser($id)
    {
        $commercial = auth()->user();

        if (!$commercial) {
            return null;
        }

        return StationService::with(['ville', 'commune'])
            ->where('created_by', $commercial->id)
            ->find($id);
    }

    protected function attachStationServiceLogoUrl($stationService)
    {
        if (!$stationService) {
            return $stationService;
        }

        $logoPath = $stationService->logo;
        $stationService->logo_path = $logoPath;
        $stationService->logo_url = null;

        if (empty($logoPath)) {
            return $stationService;
        }

        try {
            $stationService->logo_url = $this->wasabiService->temporaryUrl($logoPath) ?? $logoPath;
            $stationService->logo = $stationService->logo_url;
        } catch (\Throwable $e) {
            $stationService->logo_url = $this->wasabiService->extractPath($logoPath) ?? $logoPath;
            $stationService->logo = $stationService->logo_url;
        }

        return $stationService;
    }

    protected function formatStationServiceForResponse($stationService)
    {
        if (!$stationService) {
            return $stationService;
        }

        $stationService->ville_libelle = optional($stationService->ville)->nom;
        $stationService->commune_nom = optional($stationService->commune)->nom;

        return $this->attachStationServiceLogoUrl($stationService);
    }

    protected function stationLogoUploadErrorResponse(Request $request): ?JsonResponse
    {
        $uploadedFile = $request->file('logo');

        if ($uploadedFile instanceof UploadedFile && !$uploadedFile->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Le logo n\'a pas pu être reçu par le serveur.',
                'errors' => [
                    'logo' => [$this->uploadErrorMessage($uploadedFile->getError())],
                ],
                'limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                ],
            ], 422);
        }

        if ($request->has('logo') && !$request->hasFile('logo')) {
            return response()->json([
                'success' => false,
                'message' => 'Le champ logo a été envoyé, mais il n\'a pas été reçu comme fichier valide.',
                'errors' => [
                    'logo' => ['Envoyez le logo en multipart/form-data avec la syntaxe logo=@/chemin/vers/logo.png.'],
                ],
                'limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                ],
            ], 422);
        }

        return null;
    }

    protected function logStationLogoUploadState(Request $request, string $context): void
    {
        Log::info('Etat reception logo station: ' . $context, $this->stationLogoUploadDiagnostics($request));
    }

    protected function stationLogoUploadDiagnostics(Request $request): array
    {
        $uploadedFile = $request->file('logo');
        $fileKeys = array_keys($request->allFiles());
        $inputKeys = array_keys($request->except(['password', 'station_password']));
        $phpLogo = $_FILES['logo'] ?? null;

        $diagnostics = [
            'content_type' => $request->headers->get('content-type'),
            'content_length' => $request->headers->get('content-length'),
            'input_keys' => $inputKeys,
            'file_keys' => $fileKeys,
            'has_logo_input' => $request->has('logo'),
            'has_logo_file' => $request->hasFile('logo'),
            'php_upload_limits' => [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'max_file_uploads' => ini_get('max_file_uploads'),
            ],
            'php_files_logo' => $phpLogo ? [
                'name' => $phpLogo['name'] ?? null,
                'type' => $phpLogo['type'] ?? null,
                'size' => $phpLogo['size'] ?? null,
                'error' => $phpLogo['error'] ?? null,
            ] : null,
        ];

        if ($uploadedFile instanceof UploadedFile) {
            $diagnostics['logo_file'] = [
                'original_name' => $uploadedFile->getClientOriginalName(),
                'client_mime' => $uploadedFile->getClientMimeType(),
                'mime' => $uploadedFile->getMimeType(),
                'extension' => $uploadedFile->getClientOriginalExtension(),
                'size' => $uploadedFile->getSize(),
                'error' => $uploadedFile->getError(),
                'is_valid' => $uploadedFile->isValid(),
            ];
        } else {
            $diagnostics['logo_file'] = null;
        }

        return $diagnostics;
    }

    protected function attachStationDeLavageLogoUrl($stationDeLavage, ?string $logoPath = null)
    {
        if (!$stationDeLavage) {
            return $stationDeLavage;
        }

        $logoPath = $logoPath ?: $stationDeLavage->logo;
        $stationDeLavage->logo_path = $logoPath;
        $stationDeLavage->logo_url = null;

        if (empty($logoPath)) {
            return $stationDeLavage;
        }

        try {
            $stationDeLavage->logo_url = $this->wasabiService->temporaryUrl($logoPath) ?? $logoPath;
            $stationDeLavage->logo = $stationDeLavage->logo_url;
        } catch (\Throwable $e) {
            $stationDeLavage->logo_url = $this->wasabiService->extractPath($logoPath) ?? $logoPath;
            $stationDeLavage->logo = $stationDeLavage->logo_url;
        }

        return $stationDeLavage;
    }


	/**
     * Store a newly created resource in storage.
     */
    public function storeVehicule(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'matricule' => 'required|string|unique:vehicules',
            'carte_grise' => 'required|string|unique:vehicules',
            'photos' => 'required|array|size:4', // Vérifie que 4 fichiers sont fournis
            'photos.*' => 'file|image|max:25048', // Chaque fichier doit être une image de max 2 MB
            'type_de_vehicule_id' => 'required|exists:type_de_vehicules,id',
            'marque_id' => 'required|exists:marques,id',
            'type_de_carburant_id' => 'required|exists:type_de_carburants,id',
            'mobile_usager' => 'required|exists:users,mobile',
            'couleur' => 'required|string|max:50',
            'modele' => 'required',
        ]);

		$usager = User::where('mobile', $request->mobile_usager)->first();
		if (empty($usager)) {
            return response()->json([
                'success' => false,
                'message' => 'Usager introuvable.',
            ], 422);
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = auth()->user();
        // Vérifier si des établissements existent
        if (empty($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        DB::beginTransaction();
        try {
            // Création du véhicule
            $vehicule = new Vehicule();
            $vehicule->matricule = $request->matricule;
            $vehicule->carte_grise = $request->carte_grise;
            $vehicule->type_de_vehicule_id = $request->type_de_vehicule_id;
            $vehicule->marque_id = $request->marque_id;
            $vehicule->type_de_carburant_id = $request->type_de_carburant_id;
            $vehicule->couleur = $request->couleur;
            $vehicule->modele = $request->modele;
            $vehicule->user_id = $usager->id;
			$vehicule->created_by = $user->id;
			$vehicule->provenance = "commerciaux";
            //$vehicule->save();

            // Sauvegarde des photos via Wasabi
            if ($request->hasFile('photos')) {
                $photosPaths = [];

                foreach ($request->file('photos') as $photo) {
                    $photosPaths[] = $this->wasabiService->uploadFile(
                        $photo,
                        'vehicules/photos',
                        'vehicule'
                    );
                }

                $vehicule->photos = json_encode($photosPaths);
            }

            $vehicule->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Véhicule enregistré avec succès.',
                'vehicule' => $this->attachVehiculePhotoUrls($vehicule),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'enregistrement du véhicule.",
                'dev' => $e->getMessage(),
            ], 500);
        }
    }


    public function updateVehicule(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'matricule' => 'sometimes|string|unique:vehicules,matricule,' . $id,
            'carte_grise' => 'sometimes|string|unique:vehicules,carte_grise,' . $id,
            'photos' => 'sometimes|array|size:4',
            'photos.*' => 'file|image|max:25048',
            'type_de_vehicule_id' => 'sometimes|exists:type_de_vehicules,id',
            'marque_id' => 'sometimes|exists:marques,id',
            'type_de_carburant_id' => 'sometimes|exists:type_de_carburants,id',
            'mobile_usager' => 'sometimes|exists:users,mobile',
            'couleur' => 'sometimes|string|max:50',
            'modele' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = auth()->user();
        if (empty($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        $vehicule = Vehicule::where('id', $id)
            ->where('created_by', $user->id)
            ->first();

        if (!$vehicule) {
            return response()->json([
                'success' => false,
                'message' => 'Véhicule introuvable.',
            ], 404);
        }

        $usager = null;
        if ($request->filled('mobile_usager')) {
            $usager = User::where('mobile', $request->mobile_usager)->first();

            if (!$usager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usager introuvable.',
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            if ($request->has('matricule')) $vehicule->matricule = $request->matricule;
            if ($request->has('carte_grise')) $vehicule->carte_grise = $request->carte_grise;
            if ($request->has('type_de_vehicule_id')) $vehicule->type_de_vehicule_id = $request->type_de_vehicule_id;
            if ($request->has('marque_id')) $vehicule->marque_id = $request->marque_id;
            if ($request->has('type_de_carburant_id')) $vehicule->type_de_carburant_id = $request->type_de_carburant_id;
            if ($request->has('couleur')) $vehicule->couleur = $request->couleur;
            if ($request->has('modele')) $vehicule->modele = $request->modele;
            if ($usager) $vehicule->user_id = $usager->id;

            if ($request->hasFile('photos')) {
                if (!empty($vehicule->photos)) {
                    foreach ((array) $vehicule->photos as $photo) {
                        if (!empty($photo)) {
                            $this->wasabiService->deleteFile($photo);
                        }
                    }
                }

                $photosPaths = [];
                foreach ($request->file('photos') as $photo) {
                    $photosPaths[] = $this->wasabiService->uploadFile(
                        $photo,
                        'vehicules/photos',
                        'vehicule'
                    );
                }

                $vehicule->photos = $photosPaths;
            }

            $vehicule->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Véhicule mis à jour avec succès.',
                'vehicule' => $this->attachVehiculePhotoUrls($vehicule),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de la mise à jour du véhicule.",
                'dev' => $e->getMessage(),
            ], 500);
        }
    }


    protected function attachVehiculePhotoUrls($vehicule)
    {
        if (!$vehicule || empty($vehicule->photos)) {
            return $vehicule;
        }

        $photos = is_array($vehicule->photos)
            ? $vehicule->photos
            : json_decode($vehicule->photos, true);

        if (!is_array($photos)) {
            return $vehicule;
        }

        $vehicule->photos = array_map(function ($photo) {
            try {
                return $this->wasabiService->temporaryUrl($photo) ?? $photo;
            } catch (\Throwable $e) {
                return $photo;
            }
        }, $photos);

        return $vehicule;
    }


    /**
     * Display a listing of the resource.
     */
    public function indexVehicule()
    {
        $user = auth()->user();
        // Vérifier si des établissements existent
        if (empty($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        // Récupérer les établissements triés par ID décroissant
        $vehicules = Vehicule::where('created_by', $user->id)
		->with('marque')
        ->orderBy('id', 'desc')->get();

        // Vérifier si des établissements existent
        if ($vehicules->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun vehicule enregistré pour le moment.',
            ], 404);
        }

        // Retourner la liste des établissements
        return response()->json([
            'success' => true,
            'message' => 'Liste des vehicules.',
            'vehicules' => $vehicules->map(function ($vehicule) {
                return $this->attachVehiculePhotoUrls($vehicule);
            }),
        ], 200);
    }


    /**
     * Display a listing of the resource.
     */
    public function indexTypeDeVehicule()
    {
        $user = auth()->user();

        if (empty($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        $type_de_vehicule = Type_de_vehicule::orderBy('id', 'desc')->get();

        if ($type_de_vehicule->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun type de vehicule enregistré pour le moment.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Liste des types de vehicules.',
            'type_de_vehicules' => $type_de_vehicule,
        ], 200);
    }

    /**
     * Display a listing of the resource.
     */
    public function indexTypeDeCarburant()
    {
        $user = auth()->user();

        if (empty($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        $type_de_carburant = Type_de_carburant::orderBy('id', 'desc')->get();

        if ($type_de_carburant->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun type de carburant enregistré pour le moment.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Liste des types de carburant.',
            'type_de_carburants' => $type_de_carburant,
        ], 200);
    }

    /**
     * Display a listing of the resource.
     */
    public function indexMarque()
    {
        $user = auth()->user();

        if (empty($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        $marques = Marque::orderBy('id', 'desc')->get();

        if ($marques->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune marque enregistré pour le moment.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Liste des marque.',
            'marques' => $marques,
        ], 200);
    }

    public function assignByScan(Request $request)
	{
		try {
			Log::info('Tentative d\'attribution de QR code', ['request' => $request->all()]);

			// Validation des données d'entrée
			$validated = $request->validate([
				'qrcode' => 'required|string|max:255',
				'matricule' => 'required|string|max:50',
			]);

			// Vérification de l'authentification
			$commercial = auth('api')->user();
			if (!$commercial) {
				Log::warning('Tentative d\'attribution sans authentification');
				return response()->json([
					'status' => 'error',
					'message' => 'commercial non authentifiée'
				], 401);
			}

			// Traitement de l'attribution du QR code
			DB::beginTransaction();

			try {
				// Recherche du véhicule avec lock pessimiste
				$vehicule = Vehicule::where('matricule', $validated['matricule'])
					->lockForUpdate()
					->first();

				if (!$vehicule) {
					DB::rollBack();
					Log::warning('Véhicule non trouvé', ['matricule' => $validated['matricule']]);
					return response()->json([
						'status' => 'error',
						'message' => 'Véhicule introuvable.'
					], 404);
				}

				// Vérification si le véhicule a déjà un QR code assigné (1 véhicule = 1 QR code max)
				if ($vehicule->qrcode_generate_id) {
					DB::rollBack();
					Log::warning('Véhicule a déjà un QR code assigné', [
						'matricule' => $vehicule->matricule,
						'qrcode_id' => $vehicule->qrcode_generate_id
					]);
					return response()->json([
						'status' => 'error',
						'message' => 'Ce véhicule a déjà un QR code assigné'
					], 409);
				}

				// Recherche du QR code avec lock pessimiste
				Log::info('Recherche du QR code', ['qrcode' => $validated['qrcode']]);
				$qrcode = QrcodeGenerate::where('qrcode', $validated['qrcode'])
					->lockForUpdate()
					->first();

				if (!$qrcode) {
					DB::rollBack();
					Log::warning('QR code non trouvé', ['qrcode' => $validated['qrcode']]);
					return response()->json([
						'status' => 'error',
						'message' => 'QR code invalide'
					], 404);
				}

				// Vérification si le QR code est déjà assigné (1 QR code = 1 véhicule max)
				if ($qrcode->is_assigned) {
					DB::rollBack();
					Log::warning('QR code déjà attribué', ['qrcode' => $qrcode->qrcode]);
					return response()->json([
						'status' => 'error',
						'message' => 'Ce QR code est déjà attribué à un autre véhicule'
					], 409);
				}

				// Vérification supplémentaire : vérifier si un autre véhicule a déjà ce QR code
				$vehiculeAvecQrcode = Vehicule::where('qrcode_generate_id', $qrcode->id)
					->where('id', '!=', $vehicule->id)
					->first();

				if ($vehiculeAvecQrcode) {
					DB::rollBack();
					Log::warning('QR code déjà assigné à un autre véhicule', [
						'qrcode' => $qrcode->qrcode,
						'vehicule_existant' => $vehiculeAvecQrcode->matricule
					]);
					return response()->json([
						'status' => 'error',
						'message' => 'Ce QR code est déjà assigné à un autre véhicule'
					], 409);
				}

				// Vérification si le véhicule a un user_id valide
				if (!$vehicule->user_id) {
					DB::rollBack();
					Log::warning('Véhicule sans user_id', ['matricule' => $vehicule->matricule]);
					return response()->json([
						'status' => 'error',
						'message' => 'Véhicule non associé à un utilisateur'
					], 400);
				}

				// Création de l'historique d'attribution
				$assignment = QrcodeAssignment::create([
					'commercial_id' => $commercial->id,
					'qrcode_id' => $qrcode->id,
					'user_id' => $vehicule->user_id,
					'assigned_at' => now(),
				]);

				// Mise à jour du statut du QR code
				$qrcode->update([
					'is_assigned' => true,
					'assigned_at' => now(),
				]);

				// Association du QR code au véhicule/usager
				$vehicule->qrcode_generate_id = $qrcode->id;
				$vehicule->save();

				DB::commit();

				Log::info('QR code attribué avec succès', [
					'qrcode' => $qrcode->qrcode,
					'commercial' => $commercial->id,
					'vehicule' => $vehicule->matricule
				]);

				return response()->json([
					'status' => 'success',
					'message' => 'QR code attribué et historisé avec succès',
					'data' => [
						'qrcode' => $qrcode->qrcode,
						'assigned_at' => $assignment->assigned_at,
						'vehicule' => $vehicule->matricule
					]
				], 201);

			} catch (\Exception $e) {
				DB::rollBack();
				Log::error('Erreur lors de l\'attribution du QR code', [
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString()
				]);
				return response()->json([
					'status' => 'error',
					'message' => 'Erreur lors de l\'attribution du QR code',
					'error' => $e->getMessage()
				], 500);
			}

		} catch (\Illuminate\Validation\ValidationException $e) {
			Log::warning('Validation échouée', ['errors' => $e->errors()]);
			return response()->json([
				'status' => 'error',
				'message' => 'Données invalides',
				'errors' => $e->errors()
			], 422);
		} catch (\Exception $e) {
			Log::error('Erreur inattendue', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return response()->json([
				'status' => 'error',
				'message' => 'Une erreur inattendue est survenue',
				'error' => $e->getMessage()
			], 500);
		}
	}

    public function historyByCommercial($commercial_id)
	{
		try {
			$commercial = auth('api')->user();

			if (!$commercial) {
				return response()->json([
					'status' => 'error',
					'message' => 'Commercial non authentifiée'
				], 401);
			}

			$assignments = QrcodeAssignment::with(['qrcode', 'commercial', 'user'])
				->where('commercial_id', $commercial->id)
				->orderBy('assigned_at', 'desc')
				->get();

			// Récupérer tous les IDs de QR codes pour une requête optimisée
			$qrcodeIds = $assignments->pluck('qrcode.id')->filter()->unique()->toArray();

			// Récupérer tous les véhicules associés aux QR codes en une seule requête
			$vehicules = Vehicule::whereIn('qrcode_generate_id', $qrcodeIds)
				->with('marque')
				->get()
				->keyBy('qrcode_generate_id');

			// Associer chaque véhicule à son assignment (1 QR code = 1 véhicule)
			$assignments = $assignments->map(function ($assignment) use ($vehicules) {
				if ($assignment->user) {
					$assignment->user->makeHidden('password');
				}

				// Récupérer le véhicule associé à ce QR code
				if ($assignment->qrcode && isset($vehicules[$assignment->qrcode->id])) {
					$assignment->vehicule = $vehicules[$assignment->qrcode->id];
				}

				return $assignment;
			});

			return response()->json([
				'status' => 'success',
				'message' => $assignments->isEmpty() ? 'Aucune attribution trouvée' : 'Historique récupéré avec succès',
				'data' => [
					'history' => $assignments
				]
			], 200);

		} catch (\Exception $e) {
			Log::error('Erreur lors de la récupération de l\'historique', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return response()->json([
				'status' => 'error',
				'message' => 'Erreur lors de la récupération de l\'historique',
				'error' => $e->getMessage()
			], 500);
		}
	}

}
