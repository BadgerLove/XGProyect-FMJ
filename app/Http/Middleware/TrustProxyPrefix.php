<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustProxyPrefix
{
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = $request->header('X-Forwarded-Prefix');
        if ($prefix) {
            $request->setRoot($prefix);
        }

        return $next($request);
    }
}
