<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica o fuso da congregação da sessão em toda request web.
 *
 * ⚠ Vale só no caminho web. Comando de terminal (cron, artisan) NÃO tem
 * sessão, então quem roda fora do navegador precisa fixar o fuso por
 * congregação na mão — foi assim que o EscalaMembros levou um bug de lembrete
 * disparando na hora errada.
 */
class SetChurchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($tz = session('church_tz')) {
            date_default_timezone_set($tz);
            config(['app.timezone' => $tz]);
        }

        return $next($request);
    }
}
