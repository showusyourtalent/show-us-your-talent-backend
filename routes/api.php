<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Promoteur\PromoteurController;
use App\Http\Controllers\Api\Candidat\CandidatController;
use App\Http\Controllers\Api\Candidat\ChatController;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

// Routes candidat publiques
Route::prefix('candidat')->group(function () {
    Route::get('/editions-ouvertes', [CandidatController::class, 'getEditionsOuvertes']);
    Route::post('/postuler', [CandidatController::class, 'postuler'])->name('candidat.postuler');
});

use App\Http\Controllers\CategoryController;

Route::prefix('candidat')->group(function () {
    Route::get('/editions-ouvertes', [CandidatController::class, 'getEditionsOuvertes']);
    Route::post('/postuler', [CandidatController::class, 'postuler'])->name('candidat.postuler');
    Route::get('/categories/{editionId}', [CategoryController::class, 'getCategoriesByEdition']);
});

// OU gardez la route séparée mais avec un contrôleur
Route::get('/categories/edition/{editionId}', [CategoryController::class, 'getByEdition']);


// Routes protégées par Sanctum
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Admin routes
    Route::prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'getUsers']);
        Route::post('/users', [AdminController::class, 'createUser']);
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
        
        Route::get('/roles', [AdminController::class, 'getRoles']);
        Route::get('/statistics', [AdminController::class, 'getStatistics']);
    });

    // Promoteur routes
    Route::prefix('promoteur')->group(function () {
        // Éditions
        Route::get('/editions', [PromoteurController::class, 'getMyEditions']);
        Route::post('/editions', [PromoteurController::class, 'createEdition']);
        Route::put('/editions/{id}', [PromoteurController::class, 'updateEdition']);
        
        // Inscriptions
        Route::post('/editions/{editionId}/ouvrir-inscriptions', [PromoteurController::class, 'openRegistrations']);
        Route::post('/editions/{editionId}/fermer-inscriptions', [PromoteurController::class, 'closeRegistrations']);
        
        // Catégories
        Route::post('/editions/{edition}/categories', [PromoteurController::class, 'createCategory']);
        
        // Candidatures
        Route::get('/editions/{editionId}/candidatures', [PromoteurController::class, 'getCandidatures']);
        Route::post('/candidatures/{candidatureId}/valider', [PromoteurController::class, 'validateCandidature']);
    });

    // Candidat routes
    Route::prefix('candidat')->group(function () {
        Route::get('/mes-candidatures', [CandidatController::class, 'getMesCandidatures']);
        Route::put('/mon-profil', [CandidatController::class, 'updateProfil']);
        Route::post('/voter', [CandidatController::class, 'voter']);
    });
});

// Routes pour la gestion des votes
Route::prefix('promoteur')->middleware(['auth:sanctum'])->group(function () {
    // Gestion des votes pour les éditions
    Route::prefix('editions/{editionId}')->group(function () {
        // Configuration des dates de vote
        Route::post('/configurer-votes', [PromoteurController::class, 'configurerVotes']);
        
        // Démarrer les votes
        Route::post('/demarrer-votes', [PromoteurController::class, 'demarrerVotes']);
        
        // Suspendre les votes
        Route::post('/suspendre-votes', [PromoteurController::class, 'suspendreVotes']);
        
        // Relancer les votes
        Route::post('/relancer-votes', [PromoteurController::class, 'relancerVotes']);
        
        // Terminer les votes
        Route::post('/terminer-votes', [PromoteurController::class, 'terminerVotes']);
        
        // Modifier les dates de vote
        Route::put('/modifier-dates-votes', [PromoteurController::class, 'modifierDatesVotes']);
        
        // Obtenir les informations de vote
        Route::get('/info-votes', [PromoteurController::class, 'getInfoVotes']);
    });
});

Route::get('/candidats', [AdminController::class, 'getCandidatsEditionActive']);

Route::middleware(['auth:sanctum'])->group(function () {
    
    // Route pour voter
    Route::post('/votes', [AdminController::class, 'voter']);
    
    // Route pour vérifier si l'utilisateur a déjà voté
    Route::get('/votes/check/{candidatId}', [AdminController::class, 'checkVote']);
    
    // Route pour les statistiques de vote
    Route::get('/editions/active/statistiques', [AdminController::class, 'getStatistiquesVote']);
});

// Route publique pour voir les résultats (sans authentification)
Route::get('/editions/{editionId}/resultats', [AdminController::class, 'getResultatsPublic']);

// Route pour récupérer les candidats de l'édition active
Route::get('/candidats/edition-active', [AdminController::class, 'getCandidatsEditionActive']);
    
// Routes supplémentaires
Route::get('/candidats/{id}', [AdminController::class, 'show']);
Route::get('/candidats/category/{categoryId}', [AdminController::class, 'getCandidatsByCategory']);
Route::get('/candidats/search', [AdminController::class, 'search']);

// routes/api.php
Route::middleware(['auth:sanctum', 'cors'])->group(function () {
    // Chat routes
    Route::prefix('chat')->group(function () {
        // Rooms
        Route::get('/rooms', [ChatController::class, 'getUserRooms']);
        Route::get('/room/category/{categoryId}', [ChatController::class, 'getOrCreateRoom']);
        
        // Messages
        Route::get('/room/{roomId}/messages', [ChatController::class, 'getMessages']);
        Route::post('/room/{roomId}/message', [ChatController::class, 'sendMessage']);
        
        // Participants
        Route::get('/room/{roomId}/participants', [ChatController::class, 'getParticipants']);
        Route::post('/room/{roomId}/last-seen', [ChatController::class, 'updateLastSeen']);
        
        // Notifications
        Route::get('/notifications', [ChatController::class, 'getNotifications']);
        Route::post('/notifications/{notificationId}/read', [ChatController::class, 'markNotificationAsRead']);
        Route::post('/notifications/read-all', [ChatController::class, 'markAllNotificationsAsRead']);
    });
});

// routes/api.php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', function (Request $request) {
        return response()->json([
            'success' => true,
            'user' => $request->user()->load('roles')
        ]);
    });
    
    // Vos routes chat ici...
});


// Routes publiques
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Routes protégées par Sanctum
Route::middleware(['auth:sanctum', 'cors'])->group(function () {
    
    // Récupérer l'utilisateur connecté
    Route::get('/user', [AuthController::class, 'user']);
    
    // Chat routes
    Route::prefix('chat')->group(function () {
        // Rooms
        Route::get('/rooms', [ChatController::class, 'getUserRooms']);
        Route::get('/room/category/{categoryId}', [ChatController::class, 'getOrCreateRoom']);
        
        // Messages
        Route::get('/room/{roomId}/messages', [ChatController::class, 'getMessages']);
        Route::post('/room/{roomId}/message', [ChatController::class, 'sendMessage']);
        
        // Participants
        Route::get('/room/{roomId}/participants', [ChatController::class, 'getParticipants']);
        Route::post('/room/{roomId}/last-seen', [ChatController::class, 'updateLastSeen']);
        
        // Notifications
        Route::get('/notifications', [ChatController::class, 'getNotifications']);
        Route::post('/notifications/{notificationId}/read', [ChatController::class, 'markNotificationAsRead']);
        Route::post('/notifications/read-all', [ChatController::class, 'markAllNotificationsAsRead']);
    });
});

// Route de test
Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is working'
    ]);
});
    