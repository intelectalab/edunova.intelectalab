<?php

namespace App\Modulos\PAG\Pasarela;

/**
 * Regla 12 de PAG · Interfaz única con las pasarelas (Culqi, Niubiz…). Cada colegio cobra con
 * su propia cuenta: el dinero va directo al colegio y Edunova no recibe ni retiene fondos.
 */
interface PasarelaPagos
{
    /** Comprueba las llaves contra la pasarela (modo pruebas o producción). */
    public function probar(array $credenciales, string $ambiente): bool;

    /**
     * Crea la orden de cobro y devuelve a dónde enviar a la familia.
     *
     * @param  array{orden: string, monto: float, descripcion: string, email: string|null, medios: list<string>}  $orden
     * @return array{url: string, referencia: string|null}
     */
    public function crearOrden(array $credenciales, string $ambiente, array $orden): array;

    /** Verifica la firma del aviso (webhook) con el secreto de la cuenta del colegio. */
    public function firmaValida(string $cuerpo, array $cabeceras, string $secreto): bool;

    /**
     * Traduce el aviso al formato de Edunova.
     *
     * @return array{orden: string, estado: 'pagada'|'fallida'|'autorizada'|'reembolsada', medio: string|null, monto: float, comision: float|null, fecha_abono: string|null}
     */
    public function evento(array $payload): array;
}
