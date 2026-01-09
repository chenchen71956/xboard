<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Auth;
use Closure;
use App\Models\User;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $host = $request->getHost();
        $port = $request->getPort();
        $hostKey = $port ? ($host . ':' . $port) : $host;

        $notifyHosts = Cache::remember('notify_domain_hosts', 60, function () {
            return Payment::query()
                ->whereNotNull('notify_domain')
                ->where('notify_domain', '!=', '')
                ->get(['notify_domain'])
                ->map(function ($payment) {
                    $parts = parse_url($payment->notify_domain);
                    if (!$parts || !isset($parts['host'])) {
                        return null;
                    }
                    $host = $parts['host'];
                    if (isset($parts['port'])) {
                        $host .= ':' . $parts['port'];
                    }
                    return $host;
                })
                ->filter()
                ->values()
                ->all();
        });

        if (in_array($hostKey, $notifyHosts, true)) {
            return response('Not Found', 404);
        }

        /** @var User|null $user */
        $user = Auth::guard('sanctum')->user();
        
        if (!$user || !$user->is_admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        return $next($request);
    }
}
