<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // Développement local
        'http://localhost:5173',
        'http://localhost:3000',

        // ✅ Frontend Vercel (production)
        'https://showusyourtalent-a7l9-mks1mr82t-showusyourstalents-projects.vercel.app',
        'https://showusyourtalent-a7l9.vercel.app',

        // Variable d'environnement (optionnel)
        env('FRONTEND_URL'),
    ],

    'allowed_origins_patterns' => [
        // ✅ Couvre toutes les preview URLs Vercel (déploiements de branches)
        '#^https://showusyourtalent.*\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
