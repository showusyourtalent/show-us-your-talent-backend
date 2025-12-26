// App\Http\Middleware\HandleFedapayCallback.php
namespace App\Http\Middleware;

use Closure;
use App\Models\Payment;
use Illuminate\Http\Request;

class HandleFedapayCallback
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->route('token');
        
        if ($token) {
            $payment = Payment::where('payment_token', $token)->first();
            
            if ($payment) {
                // Ajouter les données du paiement à la requête
                $request->attributes->add(['payment' => $payment]);
            }
        }
        
        return $next($request);
    }
}