<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Models\ChatRoom;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatNotification;
use App\Models\Category;
use App\Models\Edition;
use App\Models\User;
use App\Models\Candidature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;


class ChatController extends Controller{

    public function getOrCreateRoom(Request $request, $categoryId){
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $edition = Edition::where('statut', 'active')->latest()->first();

            if (!$edition) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune édition active'
                ], 404);
            }

            $category = Category::find($categoryId);
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Catégorie non trouvée'
                ], 404);
            }

            // Vérifier si l'utilisateur a le droit d'accéder à cette catégorie
            if ($user->type_compte === 'candidat') {
                $candidature = Candidature::where('candidat_id', $user->id)
                    ->where('edition_id', $edition->id)
                    ->where('category_id', $categoryId)
                    ->where('statut', 'validée')
                    ->first();

                if (!$candidature) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vous n\'avez pas accès à cette catégorie'
                    ], 403);
                }
            }

            // Chercher ou créer la room
            $room = ChatRoom::firstOrCreate(
                [
                    'edition_id' => $edition->id,
                    'category_id' => $categoryId
                ],
                [
                    'name' => $category->nom,
                    'description' => $category->description,
                    'status' => 'active'
                ]
            );

            // Ajouter l'utilisateur comme participant s'il ne l'est pas déjà
            $participant = ChatParticipant::firstOrCreate(
                [
                    'chat_room_id' => $room->id,
                    'user_id' => $user->id
                ],
                [
                    'role' => $user->type_compte
                ]
            );

            // Ajouter automatiquement le promoteur comme participant s'il ne l'est pas
            if ($edition->promoteur_id) {
                ChatParticipant::firstOrCreate(
                    [
                        'chat_room_id' => $room->id,
                        'user_id' => $edition->promoteur_id
                    ],
                    [
                        'role' => 'promoteur'
                    ]
                );
            }

            // Charger les relations
            $room->load([
                'category',
                'participants.user',
                'lastMessage.user'
            ]);

            $room = $this->formatRoom($room, $user);

            return response()->json([
                'success' => true,
                'room' => $room
            ]);

        } 
        catch (\Exception $e) {
            Log::error('Erreur création room: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Get room messages
     */
    public function getMessages(Request $request, $roomId){
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $room = ChatRoom::find($roomId);
            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Salle de chat non trouvée'
                ], 404);
            }

            // Vérifier si l'utilisateur a accès à cette room
            if (!$this->canAccessRoom($user, $room)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Marquer les messages comme lus pour cet utilisateur
            $this->markMessagesAsRead($roomId, $user->id);

            $messages = ChatMessage::where('chat_room_id', $roomId)
                ->with(['user' => function($query) {
                    $query->select('id', 'nom', 'prenoms', 'photo_url', 'type_compte');
                }])
                ->orderBy('created_at', 'asc')
                ->paginate(50);

            return response()->json([
                'success' => true,
                'messages' => $messages,
                'room' => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'category' => $room->category
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération messages: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Send a message
     */
    public function sendMessage(Request $request, $roomId) {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:2000',
            'type' => 'sometimes|string|in:text,image,file'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $room = ChatRoom::with('edition')->find($roomId);
            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Salle de chat non trouvée'
                ], 404);
            }

            // Vérifier si l'utilisateur a accès à cette room
            if (!$this->canAccessRoom($user, $room)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Créer le message
            $message = ChatMessage::create([
                'chat_room_id' => $roomId,
                'user_id' => $user->id,
                'message' => $request->message,
                'type' => $request->type ?? 'text',
                'metadata' => $request->metadata ?? null
            ]);

            // Charger les relations
            $message->load(['user' => function($query) {
                $query->select('id', 'nom', 'prenoms', 'photo_url', 'type_compte');
            }]);

            // Créer des notifications pour les autres participants
            $this->createNotifications($room, $message, $user);

            // Mettre à jour last_seen_at pour l'expéditeur
            ChatParticipant::where('chat_room_id', $roomId)
                ->where('user_id', $user->id)
                ->update(['last_seen_at' => now()]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $message,
                'notification' => 'Message envoyé avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur envoi message: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Get room participants
     */
    public function getParticipants(Request $request, $roomId){
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $room = ChatRoom::find($roomId);
            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Salle de chat non trouvée'
                ], 404);
            }

            // Vérifier si l'utilisateur a accès à cette room
            if (!$this->canAccessRoom($user, $room)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            $participants = ChatParticipant::where('chat_room_id', $roomId)
                ->with(['user' => function($query) {
                    $query->select('id', 'nom', 'prenoms', 'photo_url', 'type_compte', 'universite');
                }])
                ->get()
                ->map(function($participant) {
                    $participant->is_online = $participant->last_seen_at 
                        ? Carbon::parse($participant->last_seen_at)->diffInMinutes(now()) < 5
                        : false;
                    return $participant;
                });

            return response()->json([
                'success' => true,
                'participants' => $participants,
                'room' => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'total_participants' => $participants->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération participants: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Get notifications for user
     */
    public function getNotifications(Request $request){
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }
            
            $notifications = ChatNotification::where('user_id', $user->id)
                ->with([
                    'room' => function($query) {
                        $query->select('id', 'name', 'category_id');
                    },
                    'room.category' => function($query) {
                        $query->select('id', 'nom');
                    },
                    'message.user' => function($query) {
                        $query->select('id', 'prenoms', 'nom');
                    }
                ])
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get()
                ->map(function($notification) {
                    $notification->formatted_time = Carbon::parse($notification->created_at)->locale('fr')->diffForHumans();
                    return $notification;
                });

            $unreadCount = $notifications->where('is_read', false)->count();

            return response()->json([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unreadCount
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération notifications: ' . $e->getMessage());
            return response()->json([
                'success' => true,
                'notifications' => [],
                'unread_count' => 0
            ]);
        }
    }

    /**
     * Mark notification as read
     */
    public function markNotificationAsRead(Request $request, $notificationId){
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $notification = ChatNotification::where('user_id', $user->id)
                ->find($notificationId);

            if ($notification) {
                $notification->update([
                    'is_read' => true,
                    'read_at' => now()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification marquée comme lue'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur marquage notification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllNotificationsAsRead(Request $request) {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }
            
            $updated = ChatNotification::where('user_id', $user->id)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now()
                ]);

            return response()->json([
                'success' => true,
                'message' => $updated . ' notifications marquées comme lues',
                'count' => $updated
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur marquage notifications: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Helper: Check if user can access room
     */
    private function canAccessRoom(User $user, ChatRoom $room): bool  {
        // Le promoteur de l'édition a accès à toutes les rooms
        if ($user->type_compte === 'promoteur') {
            $edition = $room->edition ?? Edition::find($room->edition_id);
            if ($edition && $edition->promoteur_id === $user->id) {
                return true;
            }
        }

        // Vérifier si l'utilisateur est participant
        return ChatParticipant::where('chat_room_id', $room->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Helper: Format room data
     */
    private function formatRoom($room, $user) {
        // Calculer les messages non lus
        $unreadCount = 0;
        $participant = $room->participants->where('user_id', $user->id)->first();
        
        if ($participant && $participant->last_seen_at) {
            $unreadCount = ChatMessage::where('chat_room_id', $room->id)
                ->where('created_at', '>', $participant->last_seen_at)
                ->where('user_id', '!=', $user->id)
                ->count();
        } else {
            $unreadCount = ChatMessage::where('chat_room_id', $room->id)
                ->where('user_id', '!=', $user->id)
                ->count();
        }

        $room->unread_count = $unreadCount;
        $room->total_participants = $room->participants->count();
        
        // Formater le dernier message
        if ($room->lastMessage) {
            $room->last_message = [
                'id' => $room->lastMessage->id,
                'message' => $room->lastMessage->message,
                'created_at' => $room->lastMessage->created_at,
                'user' => [
                    'id' => $room->lastMessage->user->id,
                    'prenoms' => $room->lastMessage->user->prenoms,
                    'nom' => $room->lastMessage->user->nom,
                    'photo_url' => $room->lastMessage->user->photo_url
                ]
            ];
        }

        return $room;
    }

    /**
     * Helper: Create notifications for message
     */
    private function createNotifications(ChatRoom $room, ChatMessage $message, User $sender) {
        $participants = ChatParticipant::where('chat_room_id', $room->id)
            ->where('user_id', '!=', $sender->id)
            ->with('user')
            ->get();

        foreach ($participants as $participant) {
            ChatNotification::create([
                'user_id' => $participant->user_id,
                'chat_room_id' => $room->id,
                'chat_message_id' => $message->id,
                'type' => 'new_message',
                'message' => 'Nouveau message de ' . $sender->prenoms . ' dans ' . $room->name,
                'data' => [
                    'sender_name' => $sender->prenoms . ' ' . $sender->nom,
                    'room_name' => $room->name,
                    'message_preview' => strlen($message->message) > 50 
                        ? substr($message->message, 0, 50) . '...' 
                        : $message->message
                ]
            ]);
        }
    }

    /**
     * Helper: Mark messages as read
     */
    private function markMessagesAsRead($roomId, $userId)  {
        // Mettre à jour last_seen_at
        ChatParticipant::where('chat_room_id', $roomId)
            ->where('user_id', $userId)
            ->update(['last_seen_at' => now()]);

        // Marquer les messages non lus comme lus dans la table pivot
        $unreadMessages = ChatMessage::where('chat_room_id', $roomId)
            ->where('user_id', '!=', $userId)
            ->whereDoesntHave('readers', function($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->get();

        foreach ($unreadMessages as $message) {
            $message->markAsRead($userId);
        }
    }

    /**
     * Update last seen for participant
     */
    public function updateLastSeen(Request $request, $roomId) {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $participant = ChatParticipant::where('chat_room_id', $roomId)
                ->where('user_id', $user->id)
                ->first();

            if ($participant) {
                $participant->update(['last_seen_at' => now()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Statut mis à jour'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur mise à jour last_seen: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Get user's chat rooms based on their role
     */
    public function getUserRooms(Request $request){
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $edition = Edition::where('statut', 'active')->latest()->first();

            if (!$edition) {
                return response()->json([
                    'success' => true,
                    'rooms' => [],
                    'message' => 'Aucune édition active'
                ]);
            }

            // DEBUG: Log pour voir ce qui se passe
            \Log::info('Récupération rooms pour utilisateur', [
                'user_id' => $user->id,
                'type_compte' => $user->type_compte,
                'edition_id' => $edition->id,
                'edition_nom' => $edition->nom
            ]);

            $isPromoteur = $edition->promoteur_id === $user->id;
            
            if ($user->type_compte === 'promoteur' && $isPromoteur) {
                // Initialiser les chats si aucune room n'existe
                $this->initializeChatsForPromoteur($edition);
                
                // Le promoteur voit TOUTES les rooms de l'édition active
                $rooms = ChatRoom::where('edition_id', $edition->id)
                    ->where('status', 'active')
                    ->with([
                        'category' => function($query) {
                            $query->select('id', 'nom', 'description');
                        },
                        'lastMessage.user' => function($query) {
                            $query->select('id', 'nom', 'prenoms', 'photo_url');
                        },
                        'participants.user' => function($query) {
                            $query->select('id', 'nom', 'prenoms', 'photo_url', 'type_compte');
                        }
                    ])
                    ->get()
                    ->map(function($room) use ($user) {
                        return $this->formatRoom($room, $user);
                    });
            } 
            else {
                // Pour les candidats, vérifier d'abord s'ils ont des candidatures validées
                if ($user->type_compte === 'candidat') {
                    $candidatures = Candidature::where('candidat_id', $user->id)
                        ->where('edition_id', $edition->id)
                        ->where('statut', 'validée')
                        ->pluck('category_id')
                        ->toArray();

                    \Log::info('Candidatures validées pour l\'utilisateur', [
                        'user_id' => $user->id,
                        'candidatures' => $candidatures
                    ]);

                    // Si pas de candidatures validées, retourner un tableau vide
                    if (empty($candidatures)) {
                        return response()->json([
                            'success' => true,
                            'rooms' => [],
                            'message' => 'Aucune candidature validée pour cette édition'
                        ]);
                    }

                    $rooms = ChatRoom::where('edition_id', $edition->id)
                        ->where('status', 'active')
                        ->whereIn('category_id', $candidatures)
                        ->with([
                            'category' => function($query) {
                                $query->select('id', 'nom', 'description');
                            },
                            'lastMessage.user' => function($query) {
                                $query->select('id', 'nom', 'prenoms', 'photo_url');
                            },
                            'participants.user' => function($query) {
                                $query->select('id', 'nom', 'prenoms', 'photo_url', 'type_compte');
                            }
                        ])
                        ->get()
                        ->map(function($room) use ($user) {
                            // S'assurer que l'utilisateur est bien participant
                            if (!$room->participants->contains('user_id', $user->id)) {
                                // Ajouter l'utilisateur comme participant s'il ne l'est pas
                                $room->addParticipant($user->id, $user->type_compte);
                                // Recharger les participants
                                $room->load('participants.user');
                            }
                            return $this->formatRoom($room, $user);
                        });
                } 
                else {
                    // Pour les autres types d'utilisateurs
                    $rooms = ChatRoom::where('edition_id', $edition->id)
                        ->where('status', 'active')
                        ->whereHas('participants', function($query) use ($user) {
                            $query->where('user_id', $user->id);
                        })
                        ->with([
                            'category' => function($query) {
                                $query->select('id', 'nom', 'description');
                            },
                            'lastMessage.user' => function($query) {
                                $query->select('id', 'nom', 'prenoms', 'photo_url');
                            },
                            'participants.user' => function($query) {
                                $query->select('id', 'nom', 'prenoms', 'photo_url', 'type_compte');
                            }
                        ])
                        ->get()
                        ->map(function($room) use ($user) {
                            return $this->formatRoom($room, $user);
                        });
                }
            }

            \Log::info('Rooms retournées', [
                'count' => count($rooms),
                'rooms_ids' => $rooms->pluck('id')
            ]);

            return response()->json([
                'success' => true,
                'rooms' => $rooms,
                'edition' => $edition->nom,
                'user_type' => $user->type_compte
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur récupération rooms: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    // ... [autres méthodes existantes restent inchangées] ...

    /**
     * Helper: Initialize chat rooms for promoteur if none exist
     */
    private function initializeChatsForPromoteur(Edition $edition) {
        try {
            // Vérifier si des chats existent déjà pour cette édition
            $existingRooms = ChatRoom::where('edition_id', $edition->id)->count();
            
            if ($existingRooms === 0) {
                \Log::info('Initialisation des chats pour le promoteur', [
                    'edition_id' => $edition->id,
                    'edition_nom' => $edition->nom
                ]);
                
                // Récupérer toutes les catégories
                $categories = Category::all();
                
                if ($categories->isEmpty()) {
                    \Log::warning('Aucune catégorie trouvée pour initialiser les chats');
                    return;
                }
                
                // Récupérer toutes les candidatures validées pour cette édition
                $candidatures = Candidature::where('edition_id', $edition->id)
                    ->where('statut', 'validée')
                    ->with(['candidat', 'category'])
                    ->get()
                    ->groupBy('category_id');
                
                \Log::info('Candidatures validées trouvées', [
                    'edition_id' => $edition->id,
                    'total_candidatures' => $candidatures->flatten()->count(),
                    'candidatures_par_categorie' => $candidatures->map(function($item) {
                        return $item->count();
                    })
                ]);
                
                DB::beginTransaction();
                
                foreach ($categories as $category) {
                    // Créer la room de chat
                    $room = ChatRoom::create([
                        'edition_id' => $edition->id,
                        'category_id' => $category->id,
                        'name' => $category->nom,
                        'description' => $category->description,
                        'status' => 'active'
                    ]);
                    
                    \Log::info('Chat room créée', [
                        'room_id' => $room->id,
                        'category_id' => $category->id,
                        'category_nom' => $category->nom
                    ]);
                    
                    // 1. Ajouter le promoteur comme participant
                    ChatParticipant::create([
                        'chat_room_id' => $room->id,
                        'user_id' => $edition->promoteur_id,
                        'role' => 'promoteur',
                        'last_seen_at' => now()
                    ]);
                    
                    \Log::info('Promoteur ajouté comme participant', [
                        'room_id' => $room->id,
                        'promoteur_id' => $edition->promoteur_id
                    ]);
                    
                    // 2. Ajouter les candidats de cette catégorie comme participants
                    $candidaturesCategory = $candidatures->get($category->id, collect());
                    
                    if ($candidaturesCategory->isNotEmpty()) {
                        $candidatIds = $candidaturesCategory->pluck('candidat_id')->unique();
                        $candidatsAdded = 0;
                        
                        foreach ($candidatIds as $candidatId) {
                            // Vérifier que le candidat existe et n'est pas déjà ajouté
                            $candidat = User::find($candidatId);
                            if ($candidat && $candidat->type_compte === 'candidat') {
                                ChatParticipant::create([
                                    'chat_room_id' => $room->id,
                                    'user_id' => $candidatId,
                                    'role' => 'candidat',
                                    'last_seen_at' => null // Pas encore vu
                                ]);
                                $candidatsAdded++;
                            }
                        }
                        
                        \Log::info('Candidats ajoutés comme participants', [
                            'room_id' => $room->id,
                            'category_id' => $category->id,
                            'candidats_ajoutes' => $candidatsAdded
                        ]);
                    } else {
                        \Log::info('Aucun candidat validé pour cette catégorie', [
                            'room_id' => $room->id,
                            'category_id' => $category->id
                        ]);
                    }
                    
                    // 3. Ajouter un message de bienvenue automatique
                    $welcomeMessage = ChatMessage::create([
                        'chat_room_id' => $room->id,
                        'user_id' => $edition->promoteur_id,
                        'message' => 'Bienvenue dans le chat de la catégorie "' . $category->nom . '" ! Ce salon est dédié aux échanges concernant cette catégorie.',
                        'type' => 'text',
                        'metadata' => ['is_system' => true]
                    ]);
                    
                    \Log::info('Message de bienvenue créé', [
                        'room_id' => $room->id,
                        'message_id' => $welcomeMessage->id,
                        'candidats_total' => $candidaturesCategory->count()
                    ]);
                }
                
                DB::commit();
                
                \Log::info('Initialisation des chats terminée avec succès', [
                    'edition_id' => $edition->id,
                    'rooms_created' => $categories->count(),
                    'total_candidatures' => $candidatures->flatten()->count()
                ]);
            } else {
                \Log::info('Des chats existent déjà pour cette édition', [
                    'edition_id' => $edition->id,
                    'existing_rooms' => $existingRooms
                ]);
                
                // Récupérer toutes les candidatures validées pour cette édition
                $candidatures = Candidature::where('edition_id', $edition->id)
                    ->where('statut', 'validée')
                    ->get()
                    ->groupBy('category_id');
                
                DB::beginTransaction();
                
                // Pour chaque room existante, s'assurer que tous les participants nécessaires y sont
                $existingRooms = ChatRoom::where('edition_id', $edition->id)->get();
                
                foreach ($existingRooms as $room) {
                    // 1. S'assurer que le promoteur est bien participant
                    $promoteurParticipant = ChatParticipant::firstOrCreate([
                        'chat_room_id' => $room->id,
                        'user_id' => $edition->promoteur_id
                    ], [
                        'role' => 'promoteur',
                        'last_seen_at' => now()
                    ]);
                    
                    // 2. S'assurer que les candidats validés de cette catégorie sont participants
                    $candidaturesCategory = $candidatures->get($room->category_id, collect());
                    
                    if ($candidaturesCategory->isNotEmpty()) {
                        $candidatIds = $candidaturesCategory->pluck('candidat_id')->unique();
                        $candidatsAdded = 0;
                        $candidatsExistants = 0;
                        
                        foreach ($candidatIds as $candidatId) {
                            // Vérifier si le candidat existe et n'est pas déjà participant
                            $candidat = User::find($candidatId);
                            if ($candidat && $candidat->type_compte === 'candidat') {
                                $participant = ChatParticipant::firstOrCreate([
                                    'chat_room_id' => $room->id,
                                    'user_id' => $candidatId
                                ], [
                                    'role' => 'candidat',
                                    'last_seen_at' => null
                                ]);
                                
                                if ($participant->wasRecentlyCreated) {
                                    $candidatsAdded++;
                                } else {
                                    $candidatsExistants++;
                                }
                            }
                        }
                        
                        if ($candidatsAdded > 0) {
                            \Log::info('Candidats ajoutés à la room existante', [
                                'room_id' => $room->id,
                                'category_id' => $room->category_id,
                                'candidats_ajoutes' => $candidatsAdded,
                                'candidats_existants' => $candidatsExistants
                            ]);
                        }
                    }
                }
                
                DB::commit();
                
                \Log::info('Mise à jour des participants terminée', [
                    'edition_id' => $edition->id,
                    'rooms_traitees' => $existingRooms->count(),
                    'total_candidatures' => $candidatures->flatten()->count()
                ]);
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur lors de l\'initialisation des chats pour le promoteur: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
        }
    }

    // ... [le reste de votre contrôleur reste inchangé] ...
}