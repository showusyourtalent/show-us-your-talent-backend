<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirection en cours...</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .container {
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .loader {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="loader"></div>
        <h2>Traitement en cours...</h2>
        <p>Veuillez patienter pendant que nous traitons votre paiement.</p>
    </div>
    
    <script>
        // Récupérer les paramètres d'URL
        const urlParams = new URLSearchParams(window.location.search);
        const transactionId = urlParams.get('transaction_id');
        const paymentToken = urlParams.get('payment_token');
        const status = urlParams.get('status');
        
        // Déterminer l'URL de redirection
        let redirectUrl = '/';
        
        if (status === 'cancelled') {
            // Si annulé, appeler l'API d'annulation
            fetch(`/api/payments/cancel?transaction_id=${transactionId}&payment_token=${paymentToken}`)
                .then(response => response.json())
                .then(data => {
                    if (data.redirect_url) {
                        window.location.href = data.redirect_url;
                    } else {
                        window.location.href = '/';
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    window.location.href = '/';
                });
        } else {
            // Sinon, vérifier le statut et rediriger
            fetch(`/api/payments/redirect?transaction_id=${transactionId}&payment_token=${paymentToken}&status=${status}`)
                .then(response => response.json())
                .then(data => {
                    if (data.redirect_url) {
                        window.location.href = data.redirect_url;
                    } else {
                        window.location.href = '/';
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    window.location.href = '/';
                });
        }
    </script>
</body>
</html>