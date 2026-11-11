<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PAG\Pasarela\PasarelaPagos;
use App\Modulos\PAG\Pasarela\PasarelaSimulada;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PAG-10 · Pasarela de pagos del colegio y pagos en línea.
 *
 * - EDUN-88 · El director conecta la cuenta PROPIA del colegio: llaves cifradas, prueba en modo
 *   pruebas antes de producción, URL de webhook propia y medios activos.
 * - EDUN-73 · La familia elige cargos ya emitidos (sin pagos adelantados) y paga el monto exacto:
 *   la comisión la asume el colegio (regla 10).
 * - EDUN-74 · El cargo se marca pagado solo con el webhook firmado, nunca por la redirección del
 *   navegador (regla 8); idempotente por orden; aplica a los cargos y emite la boleta.
 */
class PagosEnLinea
{
    public const PROVEEDORES = ['culqi' => 'Culqi', 'niubiz' => 'Niubiz', 'izipay' => 'Izipay', 'mercadopago' => 'Mercado Pago', 'openpay' => 'Openpay'];

    public const MEDIOS = ['tarjeta' => 'Tarjeta', 'yape' => 'Yape', 'plin' => 'Plin', 'pagoefectivo' => 'PagoEfectivo'];

    public function __construct(private Auditoria $auditoria, private Cobros $cobros, private Comprobantes $comprobantes) {}

    public static function adaptador(string $proveedor): PasarelaPagos
    {
        $clase = config('services.pasarela.simular') ? PasarelaSimulada::class : (config('services.pasarela.adaptadores')[$proveedor] ?? null);
        if (! $clase) {
            throw ValidationException::withMessages(['proveedor' => ['El conector de '.(self::PROVEEDORES[$proveedor] ?? $proveedor).' aún no está disponible.']]);
        }

        return app($clase);
    }

    public static function urlWebhook(string $token): string
    {
        return rtrim((string) config('app.url'), '/')."/api/v1/pasarela/webhook/{$token}";
    }

    public function cuentas(): array
    {
        return DB::table('pasarela_cuentas')->orderByDesc('predeterminada')->get()->map(fn ($c) => [
            'id' => $c->id, 'proveedor' => $c->proveedor, 'nombre' => self::PROVEEDORES[$c->proveedor] ?? $c->proveedor, 'ambiente' => $c->ambiente,
            'llave_publica' => $c->llave_publica, 'medios' => json_decode($c->medios, true) ?: [], 'predeterminada' => $c->predeterminada,
            'estado' => $c->estado, 'verificada_en' => $c->verificada_en, 'webhook_url' => $c->webhook_token ? self::urlWebhook($c->webhook_token) : null,
            'tiene_secreto_webhook' => (bool) $c->webhook_secreto_cifrado,
        ])->all();
    }

    public function guardarCuenta(array $d, int $usuarioId, ?int $id = null): int
    {
        $antes = $id ? DB::table('pasarela_cuentas')->find($id) : null;
        abort_if($id && ! $antes, 404);
        if (! $antes && ! filled($d['llave_secreta'] ?? null)) {
            throw ValidationException::withMessages(['llave_secreta' => ['Ingresa la llave secreta de la cuenta del colegio.']]);
        }
        $medios = array_values(array_intersect(array_keys(self::MEDIOS), $d['medios'] ?? []));
        if (! $medios) {
            throw ValidationException::withMessages(['medios' => ['Activa al menos un medio de pago.']]);
        }
        $cambiaLlaves = filled($d['llave_secreta'] ?? null) || ($antes && $antes->ambiente !== $d['ambiente']);
        $fila = [
            'proveedor' => $d['proveedor'], 'ambiente' => $d['ambiente'], 'llave_publica' => $d['llave_publica'] ?? null, 'medios' => json_encode($medios),
            'configurada_por' => $usuarioId,
        ];
        if (filled($d['llave_secreta'] ?? null)) {
            $fila['credenciales_cifradas'] = Crypt::encryptString(json_encode(['llave_secreta' => $d['llave_secreta']]));
        }
        if (filled($d['secreto_webhook'] ?? null)) {
            $fila['webhook_secreto_cifrado'] = Crypt::encryptString($d['secreto_webhook']);
        }
        if ($cambiaLlaves) {
            // Llaves o ambiente nuevos: hay que volver a probarlas antes de cobrar.
            $fila['estado'] = 'pendiente';
            $fila['verificada_en'] = null;
        }

        return DB::transaction(function () use ($fila, $id, $antes) {
            if ($id) {
                DB::table('pasarela_cuentas')->where('id', $id)->update($fila);
                $this->auditoria->registrar('pasarela_actualizada', 'pasarela_cuentas', $id, ['ambiente' => $antes->ambiente, 'estado' => $antes->estado], collect($fila)->except(['credenciales_cifradas', 'webhook_secreto_cifrado'])->all());

                return $id;
            }
            $nuevo = (int) DB::table('pasarela_cuentas')->insertGetId($fila + [
                'institucion_id' => ContextoInstitucion::requerido(), 'webhook_token' => Str::random(48),
                'webhook_secreto_cifrado' => $fila['webhook_secreto_cifrado'] ?? Crypt::encryptString(Str::random(40)),
                'predeterminada' => ! DB::table('pasarela_cuentas')->where('predeterminada', true)->exists(),
            ]);
            $this->auditoria->registrar('pasarela_configurada', 'pasarela_cuentas', $nuevo, null, collect($fila)->except(['credenciales_cifradas', 'webhook_secreto_cifrado'])->all());

            return $nuevo;
        });
    }

    /** Prueba las llaves; si responden, la cuenta queda activa (en el ambiente elegido). */
    public function probar(int $id): array
    {
        $c = DB::table('pasarela_cuentas')->find($id);
        abort_if(! $c, 404);
        $ok = self::adaptador($c->proveedor)->probar($this->credenciales($c), $c->ambiente);
        DB::table('pasarela_cuentas')->where('id', $id)->update($ok ? ['estado' => 'activa', 'verificada_en' => now()] : ['estado' => 'pendiente', 'verificada_en' => null]);
        $this->auditoria->registrar($ok ? 'pasarela_verificada' : 'pasarela_fallo_prueba', 'pasarela_cuentas', $id, null, ['ambiente' => $c->ambiente]);

        return ['ok' => $ok, 'mensaje' => $ok ? ($c->ambiente === 'pruebas' ? 'Llaves de prueba válidas. Haz un pago de prueba y luego pasa a producción.' : 'Cuenta en producción activa: ya se puede cobrar en línea.') : 'La pasarela rechazó las llaves. Revisa que correspondan al ambiente elegido.'];
    }

    /** Medios que la familia puede usar (cuenta predeterminada activa). */
    public function cuentaActiva(): ?object
    {
        return DB::table('pasarela_cuentas')->where('predeterminada', true)->where('estado', 'activa')->first();
    }

    /**
     * EDUN-73 · Orden de pago de la familia. Solo cargos de sus hijos, ya emitidos y con saldo.
     *
     * @param  list<int>  $cargoIds
     * @param  list<int>  $hijos
     * @return array{orden: string, url: string, monto: float}
     */
    public function crearOrden(array $cargoIds, array $hijos, int $usuarioId, ?string $email, array $montos = []): array
    {
        $cuenta = $this->cuentaActiva();
        if (! $cuenta) {
            throw ValidationException::withMessages(['pasarela' => ['El colegio aún no tiene pagos en línea activos.']]);
        }
        $pendientes = collect($this->cobros->pendientes($hijos, soloEmitidos: true))->keyBy('id');
        $elegidos = collect($cargoIds)->unique()->map(fn ($id) => $pendientes->get((int) $id));
        if ($elegidos->isEmpty() || $elegidos->contains(null)) {
            throw ValidationException::withMessages(['cargos' => ['Elige cargos pendientes de tus hijos que ya estén emitidos (no se pagan cuotas futuras).']]);
        }
        // EDUN-87 · Con montos (cuota de un convenio) se paga solo esa parte de cada cargo.
        if ($montos) {
            $elegidos = $elegidos->map(fn ($c) => array_merge($c, ['saldo' => min((float) $c['saldo'], round((float) ($montos[$c['id']] ?? $c['saldo']), 2))]));
        }
        $monto = round($elegidos->sum('saldo'), 2);
        $orden = 'EDU-'.ContextoInstitucion::requerido().'-'.Str::upper(Str::random(12));
        $apoderado = DB::table('apoderados')->where('user_id', $usuarioId)->value('id');
        $r = self::adaptador($cuenta->proveedor)->crearOrden($this->credenciales($cuenta), $cuenta->ambiente, [
            'orden' => $orden, 'monto' => $monto, 'descripcion' => $elegidos->pluck('descripcion')->implode(', '), 'email' => $email,
            'medios' => json_decode($cuenta->medios, true) ?: [],
        ]);
        DB::table('pasarela_transacciones')->insert([
            'institucion_id' => ContextoInstitucion::requerido(), 'pasarela_cuenta_id' => $cuenta->id, 'proveedor' => $cuenta->proveedor, 'orden' => $orden,
            'monto' => $monto, 'estado' => 'creada', 'apoderado_id' => $apoderado, 'url_pago' => $r['url'],
            'cargos' => json_encode($elegidos->map(fn ($c) => ['id' => $c['id'], 'monto' => $c['saldo']])->values()->all()),
        ]);

        return ['orden' => $orden, 'url' => $r['url'], 'monto' => $monto];
    }

    /**
     * EDUN-74 · Aviso de la pasarela. Sin sesión: la cuenta se reconoce por el token de su URL.
     *
     * @return array{estado: string, http: int}
     */
    public function webhook(string $token, string $cuerpo, array $cabeceras): array
    {
        $cuenta = DB::connection('plataforma')->table('pasarela_cuentas')->where('webhook_token', $token)->first();
        if (! $cuenta) {
            return ['estado' => 'cuenta_desconocida', 'http' => 404];
        }

        return DB::transaction(function () use ($cuenta, $cuerpo, $cabeceras) {
            ContextoInstitucion::fijar((int) $cuenta->institucion_id);
            $adaptador = self::adaptador($cuenta->proveedor);
            $secreto = $cuenta->webhook_secreto_cifrado ? Crypt::decryptString($cuenta->webhook_secreto_cifrado) : '';
            if (! $adaptador->firmaValida($cuerpo, array_change_key_case($cabeceras), $secreto)) {
                Log::warning("Webhook de pasarela con firma inválida (colegio {$cuenta->institucion_id}).");

                return ['estado' => 'firma_invalida', 'http' => 401];
            }
            $evento = $adaptador->evento(json_decode($cuerpo, true) ?: []);
            $t = DB::table('pasarela_transacciones')->where('proveedor', $cuenta->proveedor)->where('orden', $evento['orden'])->lockForUpdate()->first();
            if (! $t) {
                return ['estado' => 'orden_desconocida', 'http' => 404];
            }
            if ($t->procesada_at || in_array($t->estado, ['pagada', 'reembolsada'], true)) {
                return ['estado' => 'ya_procesada', 'http' => 200]; // Idempotente: la pasarela puede reenviar el aviso.
            }
            if ($evento['estado'] !== 'pagada') {
                DB::table('pasarela_transacciones')->where('id', $t->id)->update(['estado' => $evento['estado'], 'payload' => $cuerpo]);

                return ['estado' => $evento['estado'], 'http' => 200];
            }
            if (abs($evento['monto'] - (float) $t->monto) > 0.009) {
                Log::error("Webhook: la orden {$t->orden} llegó por S/ {$evento['monto']} y se esperaba S/ {$t->monto}.");
                DB::table('pasarela_transacciones')->where('id', $t->id)->update(['estado' => 'fallida', 'payload' => $cuerpo]);

                return ['estado' => 'monto_distinto', 'http' => 200];
            }
            $cargos = json_decode($t->cargos, true) ?: [];
            // Si mientras pagaba alguien cobró en caja, se aplica solo lo que aún tiene saldo.
            $saldos = collect($this->cobros->pendientes(DB::table('cargos')->whereIn('id', array_column($cargos, 'id'))->pluck('alumno_id')->unique()->all()))->keyBy('id');
            $aplicar = collect($cargos)->map(fn ($c) => ['id' => $c['id'], 'monto' => min((float) $c['monto'], (float) ($saldos[$c['id']]['saldo'] ?? 0))])->filter(fn ($c) => $c['monto'] > 0)->values()->all();
            $medio = in_array($evento['medio'], ['yape', 'plin', 'tarjeta', 'pagoefectivo'], true) ? $evento['medio'] : 'tarjeta';
            // Lo que la familia pagó y ya no tenía a qué aplicarse (otra orden o un cobro en caja
            // llegaron antes) queda como excedente por devolver o aplicar: no se pierde.
            $excedente = round((float) $evento['monto'] - array_sum(array_column($aplicar, 'monto')), 2);
            $pago = null;
            if ($aplicar) {
                $pago = $this->cobros->registrar($aplicar, [], ['pasarela_transaccion_id' => $t->id, 'apoderado_id' => $t->apoderado_id, 'medio' => $medio, 'referencia' => $t->orden], null);
                $ap = $t->apoderado_id ? DB::table('apoderados')->find($t->apoderado_id) : null;
                try {
                    $this->comprobantes->emitirParaPago($pago, '03', ['numero_doc' => $ap->numero_documento ?? '-', 'nombre' => $ap ? "{$ap->nombres} {$ap->apellidos}" : '', 'email' => $ap->email ?? null]);
                } catch (ValidationException $e) {
                    // El pago ya está confirmado: no se pierde; tesorería emite la boleta luego.
                    Log::error("Pago en línea {$t->orden} sin boleta: ".collect($e->errors())->flatten()->first());
                }
            }
            DB::table('pasarela_transacciones')->where('id', $t->id)->update([
                'estado' => 'pagada', 'medio' => $medio, 'comision' => $evento['comision'], 'neto' => $evento['comision'] !== null ? round($evento['monto'] - $evento['comision'], 2) : null,
                'fecha_abono' => $evento['fecha_abono'], 'payload' => $cuerpo, 'pago_id' => $pago, 'procesada_at' => now(),
                'excedente' => max(0, $excedente),
            ]);
            if ($excedente > 0.009) {
                Log::warning("Pago en línea {$t->orden} con excedente de S/ {$excedente}: los cargos ya estaban pagados.");
                $this->auditoria->registrar('pago_en_linea_excedente', 'pasarela_transacciones', (int) $t->id, null, ['orden' => $t->orden, 'excedente' => $excedente]);
            }
            $this->auditoria->registrar('pago_en_linea_confirmado', 'pasarela_transacciones', (int) $t->id, null, ['orden' => $t->orden, 'monto' => $evento['monto'], 'pago_id' => $pago]);

            return ['estado' => 'pagada', 'http' => 200];
        });
    }

    /** Excedente devuelto a la familia o aplicado a otro cargo: queda anotado quién y cómo. */
    public function resolverExcedente(int $id, string $nota, int $usuarioId): void
    {
        $t = DB::table('pasarela_transacciones')->where('id', $id)->lockForUpdate()->first();
        abort_if(! $t, 404);
        if ((float) $t->excedente <= 0 || $t->excedente_resuelto_at) {
            throw ValidationException::withMessages(['excedente' => ['Ese pago no tiene un excedente pendiente.']]);
        }
        DB::table('pasarela_transacciones')->where('id', $id)->update(['excedente_resuelto_at' => now(), 'excedente_resuelto_por' => $usuarioId, 'excedente_nota' => trim($nota)]);
        $this->auditoria->registrar('pago_en_linea_excedente_resuelto', 'pasarela_transacciones', $id, null, ['nota' => trim($nota)]);
    }

    /** Página de pago simulada: arma el aviso firmado como lo haría la pasarela y lo procesa. */
    public function simularPago(string $orden, string $resultado, string $medio, array $hijos): array
    {
        abort_unless(config('services.pasarela.simular'), 404);
        $t = DB::table('pasarela_transacciones')->where('orden', $orden)->first();
        abort_if(! $t, 404);
        $cargos = array_column(json_decode($t->cargos, true) ?: [], 'id');
        abort_unless(DB::table('cargos')->whereIn('id', $cargos)->whereNotIn('alumno_id', $hijos)->doesntExist(), 403);
        $cuenta = DB::table('pasarela_cuentas')->find($t->pasarela_cuenta_id);
        $cuerpo = json_encode(['orden' => $orden, 'estado' => $resultado, 'medio' => $medio, 'monto' => (float) $t->monto]);
        $firma = PasarelaSimulada::firmar($cuerpo, Crypt::decryptString($cuenta->webhook_secreto_cifrado));

        return $this->webhook($cuenta->webhook_token, $cuerpo, [PasarelaSimulada::CABECERA => $firma]);
    }

    public function transaccion(string $orden): ?array
    {
        $t = DB::table('pasarela_transacciones')->where('orden', $orden)->first(['orden', 'monto', 'estado', 'medio', 'pago_id', 'cargos', 'created_at']);
        if (! $t) {
            return null;
        }
        $comprobante = $t->pago_id ? DB::table('comprobantes')->where('pago_id', $t->pago_id)->whereIn('tipo', ['01', '03'])->first(['id', 'serie', 'numero', 'estado_sunat', 'pdf_archivo_id']) : null;

        return array_merge((array) $t, ['monto' => (float) $t->monto, 'cargos' => json_decode($t->cargos, true) ?: [], 'comprobante' => $comprobante ? (array) $comprobante : null]);
    }

    private function credenciales(object $cuenta): array
    {
        try {
            return json_decode(Crypt::decryptString($cuenta->credenciales_cifradas), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }
}
