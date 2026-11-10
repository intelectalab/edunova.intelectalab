<?php

namespace App\Modulos\SEG\Http\Controllers;

use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EDUN-40 · SEG-03 Auditoría del colegio: antes y después de cada cambio, filtros y
 * exportación. No hay ruta para borrar ni editar (y la base tampoco lo permite).
 */
class AuditoriaController
{
    public function __construct(private Auditoria $auditoria) {}

    public function index(Request $request): JsonResponse
    {
        $pagina = $this->auditoria->consulta($this->filtros($request))->paginate(30);
        $pagina->getCollection()->transform(fn ($f) => self::fila($f));

        return response()->json($pagina);
    }

    public function opciones(): JsonResponse
    {
        return response()->json([
            'acciones' => collect(Auditoria::ACCIONES)->map(fn ($n, $c) => ['codigo' => $c, 'nombre' => $n])->values(),
            'tablas' => DB::table('auditoria')->distinct()->orderBy('tabla')->pluck('tabla'),
            'usuarios' => DB::table('auditoria as a')->join('users as u', 'u.id', '=', 'a.user_id')->distinct()
                ->orderBy('nombre')->get(['u.id', DB::raw("u.nombres || ' ' || u.apellidos as nombre")]),
        ]);
    }

    /** Tope de filas por exportación (con filtros de fecha se exporta el resto). */
    public const MAX_EXPORTAR = 20000;

    public function exportar(Request $request): StreamedResponse
    {
        // Las filas se leen aquí, dentro de la transacción del colegio activo (RLS): el
        // cuerpo de la descarga se escribe después de que el middleware la cierra.
        $filas = $this->auditoria->consulta($this->filtros($request))->limit(self::MAX_EXPORTAR)->get();

        return response()->streamDownload(function () use ($filas) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Fecha', 'Usuario', 'Acción', 'Tabla', 'Registro', 'Antes', 'Después', 'IP'], escape: '');
            foreach ($filas as $f) {
                $r = self::fila($f);
                fputcsv($salida, [
                    $r['fecha'], $r['usuario'], $r['accion_nombre'], $r['tabla'], $r['registro_id'],
                    self::seguro(json_encode($r['antes'], JSON_UNESCAPED_UNICODE)), self::seguro(json_encode($r['despues'], JSON_UNESCAPED_UNICODE)), $r['ip'],
                ], escape: '');
            }
            fclose($salida);
        }, 'auditoria-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtros(Request $request): array
    {
        return $request->validate([
            'usuario' => ['nullable', 'integer'],
            'accion' => ['nullable', 'string', 'max:40'],
            'tabla' => ['nullable', 'string', 'max:60'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
    }

    private static function fila(object $f): array
    {
        return [
            'id' => (int) $f->id,
            'fecha' => (string) $f->created_at,
            'usuario' => $f->usuario,
            'user_id' => $f->user_id,
            'accion' => $f->accion,
            'accion_nombre' => Auditoria::ACCIONES[$f->accion] ?? $f->accion,
            'tabla' => $f->tabla,
            'registro_id' => $f->registro_id,
            'antes' => $f->antes === null ? null : json_decode($f->antes, true),
            'despues' => $f->despues === null ? null : json_decode($f->despues, true),
            'ip' => $f->ip,
        ];
    }

    /** Evita fórmulas al abrir el CSV en una hoja de cálculo. */
    private static function seguro(?string $texto): string
    {
        $texto = (string) $texto;

        return $texto !== '' && in_array($texto[0], ['=', '+', '-', '@'], true) ? "'".$texto : $texto;
    }
}
