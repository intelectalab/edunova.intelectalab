import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Linea = { descripcion: string; cantidad: number; monto: number };
type Calculo = {
  suscripcion_id: number; colegio: string; alumnos: number; plan: string; gratis: boolean; congelado: boolean; sin_precio: boolean;
  comprobantes_emitidos: number; comprobantes_cobrados: number; total: number; lineas: Linea[]; factura: { id: number; estado: string; comprobante_serie_numero: string } | null;
};
type Factura = { id: number; periodo: string; colegio: string; monto: number; comprobante_serie_numero: string; estado: string; emitida_at: string | null; pagada_at: string | null };
type Datos = { periodo: string; calculo: Calculo[]; facturas: Factura[] };

/** Mes en curso (al cargar la consola). */
const MES_ACTUAL = new Date().toISOString().slice(0, 7);

const soles = (n: number) => `S/ ${n.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** EDUN-27 · PLA-H04 Cobro mensual de Edunova a los colegios. */
export function CobroMensual() {
  const { pedir } = useSesion();
  const [periodo, setPeriodo] = useState(MES_ACTUAL);
  const { datos, setDatos, error, recargar } = useCarga(() => pedir<Datos>(`/plataforma/cobro-mensual?periodo=${periodo}`), [periodo]);
  const accion = useAccion();
  const pendientes = datos?.calculo.filter((c) => !c.factura && !c.sin_precio && c.total > 0) ?? [];
  return (
    <>
      <Titulo migas="Plataforma" titulo="Cobro mensual" descripcion="Alumnos matriculados al día 1, meses gratis, precio congelado y comprobantes emitidos sobre los incluidos en el plan.">
        <Campo etiqueta="Mes"><input type="month" value={periodo} onChange={(e) => setPeriodo(e.target.value)} /></Campo>
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos && (
        <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
          <div className="tarjeta">
            <div className="titulo-fila">
              <h2>Cálculo de {datos.periodo}</h2>
              <button type="button" className="boton" disabled={!pendientes.length || accion.enviando}
                onClick={() => window.confirm(`¿Emitir ${pendientes.length} comprobantes por ${soles(pendientes.reduce((s, c) => s + c.total, 0))}?`) && accion.ejecutar(async () => {
                  const r = await pedir<{ emitidas: unknown[]; omitidas: string[]; facturas: Factura[] }>('/plataforma/cobro-mensual', { metodo: 'POST', cuerpo: { periodo } });
                  recargar();
                  accion.setOk(`Emitidos ${r.emitidas.length} comprobantes.${r.omitidas.length ? ` Omitidos: ${r.omitidas.join(' ')}` : ''}`);
                })}>Emitir {pendientes.length} cobros</button>
            </div>
            <div className="tabla-caja">
              <table className="tabla">
                <thead><tr><th>Colegio</th><th>Alumnos</th><th>Plan</th><th>Detalle</th><th>Total</th><th>Estado</th></tr></thead>
                <tbody>
                  {datos.calculo.map((c) => (
                    <tr key={c.suscripcion_id}>
                      <td>{c.colegio}</td><td>{c.alumnos}</td><td>{c.plan}{c.congelado && <> <span className="insignia">congelado</span></>}</td>
                      <td>{c.lineas.map((l, i) => <div key={i}><small>{l.descripcion}: {soles(l.monto)}</small></div>)}</td>
                      <td><b>{soles(c.total)}</b></td>
                      <td>
                        {c.factura ? <span className="insignia insignia-ok">{c.factura.comprobante_serie_numero}</span>
                          : c.sin_precio ? <span className="insignia insignia-rojo">Plan sin precio</span>
                            : c.gratis ? <span className="insignia">Mes gratis</span> : <span className="insignia insignia-ambar">Por emitir</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {datos.calculo.length === 0 && <p className="vacio">No hay suscripciones activas en ese mes.</p>}
            </div>
          </div>
          <div className="tarjeta">
            <h2>Comprobantes emitidos</h2>
            <div className="tabla-caja">
              <table className="tabla">
                <thead><tr><th>Comprobante</th><th>Colegio</th><th>Monto</th><th>Emitido</th><th>Estado</th><th /></tr></thead>
                <tbody>
                  {datos.facturas.map((f) => (
                    <tr key={f.id}>
                      <td><b>{f.comprobante_serie_numero}</b></td><td>{f.colegio}</td><td>{soles(f.monto)}</td><td>{fechaHora(f.emitida_at)}</td>
                      <td><span className={`insignia ${f.estado === 'pagada' ? 'insignia-ok' : f.estado === 'anulada' ? 'insignia-rojo' : 'insignia-ambar'}`}>{f.estado}</span></td>
                      <td>
                        {f.estado === 'emitida' && (
                          <button type="button" className="boton boton-chico boton-borde" onClick={() => accion.ejecutar(async () => {
                            await pedir(`/plataforma/facturas-saas/${f.id}`, { metodo: 'PATCH', cuerpo: { estado: 'pagada' } });
                            setDatos({ ...datos, facturas: datos.facturas.map((x) => (x.id === f.id ? { ...x, estado: 'pagada' } : x)) });
                          }, 'Marcado como pagado.')}>Marcar pagado</button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {datos.facturas.length === 0 && <p className="vacio">Aún no se emitió el cobro de este mes.</p>}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
