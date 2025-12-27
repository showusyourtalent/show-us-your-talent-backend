<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Promoteur\PromoteurController;
use App\Http\Controllers\Api\Candidat\CandidatController;
use App\Http\Controllers\Api\DashboardCandidatController;
use App\Http\Controllers\Api\CandidatureController;
use App\Http\Controllers\Api\Candidat\ChatController;
use App\Http\Controllers\VoteController;
use App\Http\Controllers\Api\PaymentController;

// routes/web.php
Route::get('/payment/success/{token}', [PaymentController::class, 'redirectSuccess'])
    ->name('payment.redirect.success');

Route::get('/payment/failed/{token}', [PaymentController::class, 'redirectFailed'])
    ->name('payment.redirect.failed');

Route::get('/payment/cancel/{token}', [PaymentController::class, 'redirectCancel'])
    ->name('payment.redirect.cancel');

// Routes publiques
Route::middleware(['api', 'throttle:60,1'])->group(function () {
    // Routes publiques existantes...
    
    // Webhook FedaPay - accepter GET et POST
    Route::match(['GET', 'POST'], '/payments/webhook', [PaymentController::class, 'webhook'])->name('payment.webhook');
    
    // Route pour l'annulation explicite
    Route::match(['GET', 'POST'], '/payments/cancel', [PaymentController::class, 'handleCancellation'])->name('payment.cancel.webhook');
    
    // Route pour vérifier et rediriger après paiement
    Route::get('/payments/redirect', [PaymentController::class, 'handlePaymentRedirect'])->name('payment.redirect');
});

Route::get('/payments/redirect-handler', function() {
    return view('payments.redirect_handler');
});

Route::middleware(['api', 'throttle:60,1'])->group(function () {

    Route::get('/payments/callback', [PaymentController::class, 'fedapayCallback'])->name('payment.callback');
    // Routes publiques
    Route::get('/editions/{edition}/candidats', [VoteController::class, 'getCandidats']);
    Route::get('/editions/{edition}/statistics', [VoteController::class, 'getEditionStatistics']);
    Route::get('/categories', [VoteController::class, 'getCategories']);
    Route::get('/payments/{token}/verify', [PaymentController::class, 'verifyPayment'])->name('payment.verify');
    
    // Webhook FedaPay (sans authentification)
    Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->name('payment.webhook');
});

Route::middleware(['api', 'throttle:30,1'])->group(function () {
    // Votes
    Route::post('/votes', [VoteController::class, 'vote'])->name('vote.create');
    Route::get('/votes/history', [VoteController::class, 'voteHistory']);
    Route::get('/votes/statistics', [VoteController::class, 'getUserStatistics']);
    
    // Paiements
    Route::post('/payments/initiate', [PaymentController::class, 'initiatePayment'])->name('payment.initiate');
    Route::post('/payments/process', [PaymentController::class, 'processPayment'])->name('payment.process');
    Route::get('/payments/{token}/verify', [PaymentController::class, 'verifyPayment'])->name('payment.verify');
    Route::get('/payments/{token}/status', [PaymentController::class, 'checkPaymentStatus'])->name('payment.status');
    Route::get('/payments/{token}/success', [PaymentController::class, 'paymentSuccess'])->name('payment.success');
    Route::get('/payments/{token}/failed', [PaymentController::class, 'paymentFailed'])->name('payment.failed');
    Route::post('/payments/{token}/cancel', [PaymentController::class, 'cancelPayment'])->name('payment.cancel');
    Route::get('/payments/history', [PaymentController::class, 'paymentHistory']);
    
    // Webhook
    Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->name('payment.webhook')->withoutMiddleware(['api', 'throttle']);
});

// Routes publiques pour les redirections
Route::middleware('web')->group(function () {
    Route::get('/payments/{token}/success/redirect', [PaymentController::class, 'redirectSuccess'])->name('payment.success.redirect');
    Route::get('/payments/{token}/failed/redirect', [PaymentController::class, 'redirectFailed'])->name('payment.failed.redirect');
    Route::get('/payments/callback', [PaymentController::class, 'fedapayCallback'])->name('payment.callback');
    Route::get('/payments/{token}/cancel', [PaymentController::class, 'cancelPayment'])->name('payment.cancel.get');
});

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
    // Votes
    Route::post('/votes', [VoteController::class, 'vote']);
    Route::get('/votes/statistics/{editionId}', [VoteController::class, 'getStatistics']);
    
    // Paiements

    Route::get('/payments/create', [PaymentController::class, 'create'])->name('payment.create');
    Route::get('/payments/confirm/{token}', [PaymentController::class, 'confirm'])->name('payment.confirm');
    Route::get('/payments/success/{token}', [PaymentController::class, 'success'])->name('payment.success');
    Route::get('/payments/failed/{token}', [PaymentController::class, 'failed'])->name('payment.failed');
    Route::get('/payments/check-status/{token}', [PaymentController::class, 'checkStatus'])->name('payment.checkStatus');
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


// Dans routes/api.php (pour le développement seulement)
if (app()->environment('local')) {
    Route::get('/test-cancel/{transactionId}', function($transactionId) {
        // Simuler une annulation FedaPay
        return redirect()->to('/api/payments/callback?id=' . $transactionId . '&status=pending&close=true');
    });
}

// Route pour les annulations
Route::get('/payments/cancelled', function(Request $request) {
    return view('payments.close_and_redirect', [
        'message' => 'Paiement annulé',
        'auto_close' => true,
        'redirect_url' => url('/') // Rediriger vers l'accueil
    ]);
})->name('payment.cancelled.page');

// Modifiez la route webhook pour accepter GET
Route::match(['GET', 'POST'], '/payments/webhook', [PaymentController::class, 'webhook']);

Route::middleware('auth:sanctum')->group(function () {
    // Routes pour les candidatures
    Route::prefix('candidat')->group(function () {
        
        // Nouvelles routes pour les statistiques
        Route::get('/statistiques', [CandidatureController::class, 'getStatistiques']);
        Route::get('/votes/{edition_id?}/{category_id?}', [VoteController::class, 'getVotesForCandidat']);
        Route::get('/classement/{edition_id?}/{category_id?}', [VoteController::class, 'getClassement']);
    });

    // Routes pour les statistiques générales
    Route::prefix('stats')->group(function () {
        Route::get('/candidat', [StatsController::class, 'getCandidatStats']);
        Route::get('/edition/{editionId}', [StatsController::class, 'getEditionStats']);
        Route::get('/category/{categoryId}', [StatsController::class, 'getCategoryStats']);
    });
});

Route::prefix('candidat/dashboard')->group(function () {
    // Statistiques globales du dashboard
    Route::get('/stats', [DashboardCandidatController::class, 'getDashboardStats']);
        
    // Statistiques détaillées par édition/catégorie
    Route::get('/stats/detailed', [DashboardCandidatController::class, 'getDetailedStats']);
});