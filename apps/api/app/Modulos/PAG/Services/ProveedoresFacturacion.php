<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-90 · PAG-H16 Proveedores de facturación electrónica.
 *
 * Dos proveedores posibles (los nombres reales se configuran en el servidor, no en el código),
 * uno predeterminado, ambiente beta o producción y series por tipo de comprobante. Las
 * credenciales se guardan cifradas y nunca se devuelven.
 */
class ProveedoresFacturacion
{
    public function __construct(private Auditoria $auditoria) {}

    /** @return array<string, string> código => nombre visible */
    public static function catalogo(): array
    {
        $catalogo = [];
        foreach (explode(',', (string) config('services.facturacion.proveedores')) as $par) {
            [$codigo, $nombre] = array_pad(array_map('trim', explode(':', $par, 2)), 2, null);
            if ($codigo) {
                $catalogo[$codigo] = $nombre ?: $codigo;
            }
        }

        return $catalogo;
    }

    public function listar(): array
    {
        $catalogo = self::catalogo();

        return DB::table('proveedores_facturacion')->orderByDesc('predeterminado')->orderBy('proveedor')->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'proveedor' => $p->proveedor, 'nombre' => $catalogo[$p->proveedor] ?? $p->proveedor, 'ambiente' => $p->ambiente,
                'serie_boleta' => $p->serie_boleta, 'serie_factura' => $p->serie_factura, 'serie_nota_credito' => $p->serie_nota_credito,
                'predeterminado' => $p->predeterminado, 'activo' => $p->activo, 'tiene_credenciales' => $p->credenciales_cifradas !== '',
            ])->all();
    }

    public function guardar(array $d, ?int $id = null): int
    {
        $catalogo = self::catalogo();
        if (! isset($catalogo[$d['proveedor']])) {
            throw ValidationException::withMessages(['proveedor' => ['Elige uno de los proveedores disponibles.']]);
        }
        $antes = $id ? DB::table('proveedores_facturacion')->find($id) : null;
        abort_if($id && ! $antes, 404);
        if (DB::table('proveedores_facturacion')->where('proveedor', $d['proveedor'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            throw ValidationException::withMessages(['proveedor' => ['Ese proveedor ya está configurado.']]);
        }
        if (! $antes && ! filled($d['credenciales'] ?? null)) {
            throw ValidationException::withMessages(['credenciales' => ['Ingresa las credenciales del proveedor.']]);
        }
        $activo = (bool) ($d['activo'] ?? true);
        $hayOtroPredeterminado = DB::table('proveedores_facturacion')->where('predeterminado', true)->where('activo', true)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        $predeterminado = $activo && ((bool) ($d['predeterminado'] ?? false) || ! $hayOtroPredeterminado);

        $fila = [
            'proveedor' => $d['proveedor'], 'ambiente' => $d['ambiente'],
            'serie_boleta' => mb_strtoupper($d['serie_boleta']), 'serie_factura' => mb_strtoupper($d['serie_factura']),
            'serie_nota_credito' => filled($d['serie_nota_credito'] ?? null) ? mb_strtoupper($d['serie_nota_credito']) : null,
            'predeterminado' => $predeterminado, 'activo' => $activo,
        ];
        if (filled($d['credenciales'] ?? null)) {
            $fila['credenciales_cifradas'] = Crypt::encryptString($d['credenciales']);
        }

        return DB::transaction(function () use ($fila, $id, $antes, $predeterminado) {
            if ($predeterminado) {
                DB::table('proveedores_facturacion')->when($id, fn ($q) => $q->where('id', '<>', $id))->update(['predeterminado' => false]);
            }
            $visible = collect($fila)->except('credenciales_cifradas')->all() + ['credenciales_cambiadas' => isset($fila['credenciales_cifradas'])];
            if ($id) {
                DB::table('proveedores_facturacion')->where('id', $id)->update($fila);
                $this->auditoria->registrar('proveedor_facturacion_actualizado', 'proveedores_facturacion', $id, collect((array) $antes)->except('credenciales_cifradas')->all(), $visible);

                return $id;
            }
            $nuevo = (int) DB::table('proveedores_facturacion')->insertGetId($fila + ['institucion_id' => ContextoInstitucion::requerido()]);
            $this->auditoria->registrar('proveedor_facturacion_creado', 'proveedores_facturacion', $nuevo, null, $visible);

            return $nuevo;
        });
    }

    /** El proveedor con el que se emitirán los comprobantes del colegio (lo usa PAG-H09). */
    public function predeterminado(): ?object
    {
        return DB::table('proveedores_facturacion')->where('predeterminado', true)->where('activo', true)->first();
    }
}
