<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ChatSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $edition = \App\Models\Edition::where('statut', 'active')->first();
        
        if (!$edition) return;
        
        // Créer des rooms pour chaque catégorie
        $categories = \App\Models\Category::where('edition_id', $edition->id)->get();
        
        foreach ($categories as $category) {
            $room = \App\Models\ChatRoom::create([
                'name' => $category->nom,
                'description' => $category->description,
                'category_id' => $category->id,
                'edition_id' => $edition->id,
                'status' => 'active'
            ]);
            
            // Ajouter le promoteur comme participant
            if ($edition->promoteur_id) {
                \App\Models\ChatParticipant::create([
                    'chat_room_id' => $room->id,
                    'user_id' => $edition->promoteur_id,
                    'role' => 'promoteur'
                ]);
            }
        }
    }
}
