<?php

namespace App\Modulos\SIT\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 6 · Formularios del sitio público. El navegador no habla con la API: el servidor de
 * Next.js reenvía el formulario. Para que los límites de uso y el registro de consentimientos
 * usen la IP y el navegador del visitante (no los del servidor), el sitio los manda en
 * X-Visitante-Ip y X-Visitante-Agente junto con X-Sitio-Secreto. Sin el secreto correcto se
 * ignoran y valen los de la conexión.
 */
class VisitanteSitio
{
    public function handle(Request $request, Closure $next): Response
    {
        [$ip, $agente] = self::resolver($request);
        $request->attributes->set('visitante_ip', $ip);
        $request->attributes->set('visitante_agente', $agente);

        return $next($request);
    }

    /** @return array{0: ?string, 1: string} IP y navegador del visitante. */
    private static function resolver(Request $request): array
    {
        $ip = $request->ip();
        $agente = (string) $request->userAgent();

        $secreto = (string) config('services.sitio.proxy_secreto');
        if ($secreto !== '' && hash_equals($secreto, (string) $request->header('X-Sitio-Secreto'))) {
            $reenviada = trim((string) $request->header('X-Visitante-Ip'));
            if (filter_var($reenviada, FILTER_VALIDATE_IP)) {
                $ip = $reenviada;
            }
            $agente = (string) $request->header('X-Visitante-Agente', $agente);
        }

        return [$ip, mb_substr($agente, 0, 500)];
    }

    public static function ip(Request $request): ?string
    {
        return $request->attributes->get('visitante_ip') ?? self::resolver($request)[0];
    }

    public static function agente(Request $request): ?string
    {
        return $request->attributes->get('visitante_agente') ?? self::resolver($request)[1];
    }
}
