<?php
namespace Database\Seeders;

use App\Models\Edition;
use App\Models\VoteSetting;
use Illuminate\Database\Seeder;

class VoteSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $edition = Edition::firstOrCreate(
            ['statut' => 'terminee'],
            [
                'nom' => 'Édition 2025',
                'annee' => 2025,
                'numero_edition' => 1,
                'promoteur_id'=> 2,
                'votes_ouverts' => false,
                'statut_votes' => 'en_attente',
            ]
        );


        // 2️⃣ Créer ou mettre à jour les paramètres de vote
        VoteSetting::updateOrCreate(
            [
                'edition_id' => $edition->id,
                'category_id' => null,
            ],
            [
                'vote_price' => 100,
                'is_paid' => true,
                'free_votes_per_user' => 3,
                'max_votes_per_user' => 100,
                'max_votes_per_candidat' => 1000,
                'vote_start' => now(),
                'vote_end' => now()->addDays(30),
                'allow_mobile_money' => true,
                'allow_card' => true,
                'allow_bank_transfer' => true,
            ]
        );
    }
}
