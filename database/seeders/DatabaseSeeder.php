<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ===== ÉTAPE CRITIQUE : Nettoyer le cache =====
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ===== OPTION A : Utiliser 'web' comme guard (plus simple) =====
        $guard = 'sanctum'; // Changez 'sanctum' en 'web' partout
        
        // ===== OPTION B : Si vous voulez garder 'sanctum', assurez-vous que votre User modèle l'utilise =====
        // $guard = 'sanctum';

        // ===== 1. Création des permissions =====
        $permissions = [
            // Administrateur
            'gérer_tous_utilisateurs',
            'gérer_rôles',
            'voir_statistiques',
            'configurer_système',
            
            // Promoteur
            'créer_édition',
            'modifier_édition',
            'supprimer_édition',
            'gérer_catégories',
            'ouvrir_inscriptions',
            'fermer_inscriptions',
            'ouvrir_votes',
            'fermer_votes',
            'valider_candidatures',
            'gérer_partenaires',
            'voir_candidatures_édition',
            
            // Candidat
            'postuler_édition',
            'modifier_profil',
            'voir_mes_candidatures',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => $guard // Utilisez la même variable partout
            ]);
        }

        // ===== 2. Création des rôles =====
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => $guard
        ]);

        $promoteurRole = Role::firstOrCreate([
            'name' => 'promoteur',
            'guard_name' => $guard
        ]);

        $candidatRole = Role::firstOrCreate([
            'name' => 'candidat',
            'guard_name' => $guard
        ]);

        // ===== 3. Attribution des permissions =====
        $adminRole->syncPermissions(Permission::where('guard_name', $guard)->get());
        
        $promoteurPermissions = [
            'créer_édition',
            'modifier_édition',
            'supprimer_édition',
            'gérer_catégories',
            'ouvrir_inscriptions',
            'fermer_inscriptions',
            'ouvrir_votes',
            'fermer_votes',
            'valider_candidatures',
            'gérer_partenaires',
            'voir_candidatures_édition',
        ];
        $promoteurRole->syncPermissions($promoteurPermissions);
        
        $candidatRole->syncPermissions([
            'postuler_édition',
            'modifier_profil',
            'voir_mes_candidatures',
        ]);

        // ===== 4. Création des utilisateurs =====
        
        // Création d'un admin principal
        $admin = User::firstOrCreate(
            ['email' => 'admin@showusyourtalent.com'],
            [
                'nom' => 'Admin',
                'prenoms' => 'Principal',
                'password' => Hash::make('Adminstrateur_12345678'),
                'type_compte' => 'admin',
                'email_verified_at' => now(),
                'compte_actif' => true,
            ]
        );
        
        // Assigner le rôle
        if (!$admin->hasRole('admin')) {
            $admin->assignRole($adminRole); // Utilisez l'objet rôle directement
        }

        // Création d'un promoteur exemple
        $promoteur = User::firstOrCreate(
            ['email' => 'promoteur@showusyourtalent.com'],
            [
                'nom' => 'Promoteur',
                'prenoms' => 'Test',
                'password' => Hash::make('12345678'),
                'type_compte' => 'promoteur',
                'email_verified_at' => now(),
                'compte_actif' => true,
            ]
        );
        
        
        if (!$promoteur->hasRole('promoteur')) {
            $promoteur->assignRole($promoteurRole);
        }

        // ===== 5. Message de confirmation =====
        $this->command->info('✅ Base de données peuplée avec succès !');
        $this->command->info('Admin: admin@showusyourtalent.com / Adminstrateur_12345678');
        $this->command->info('Promoteur: promoteur@showusyourtalent.com / 12345678');

        $this->call([
            VoteSettingsSeeder::class,
            ChatSeeder::class,
        ]);
    }
}


///  php artisan migrate --seed
