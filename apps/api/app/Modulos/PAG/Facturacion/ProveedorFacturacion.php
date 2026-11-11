<?php

namespace App\Modulos\PAG\Facturacion;

/**
 * Regla 6 de PAG · Interfaz única con los proveedores de facturación electrónica (OSE/PSE).
 * Cada proveedor real es un adaptador; mientras no haya credenciales reales se usa el simulado.
 */
interface ProveedorFacturacion
{
    /**
     * Envía el comprobante y devuelve la respuesta normalizada.
     *
     * @param  array<string, mixed>  $documento  cabecera, cliente, ítems y totales
     * @param  array<string, mixed>  $credenciales  descifradas
     * @return array{estado: 'aceptado'|'observado'|'rechazado', xml: string, mensaje: string, respuesta: array<string, mixed>}
     *
     * @throws \RuntimeException si el proveedor no responde (se reintenta)
     */
    public function emitir(array $documento, array $credenciales, string $ambiente): array;
}
