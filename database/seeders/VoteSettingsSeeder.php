<?php
namespace Database\Seeders;

use App\Models\Edition;
use App\Models\VoteSetting;
use Illuminate\Database\Seeder;

class VoteSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // 1️⃣ Récupérer ou créer l’édition active
        $edition = Edition::firstOrCreate(
            ['statut' => 'active'],
            [
                'name' => 'Édition 2026',
                'start_date' => now(),
                'end_date' => now()->addMonths(1),
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
