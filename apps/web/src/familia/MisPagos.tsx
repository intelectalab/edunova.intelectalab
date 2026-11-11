import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Titulo } from '../panel/ui';
import { fecha, useAccion, useCarga } from '../panel/utilidades';
import { soles } from '../pag/tipos';

type Cargo = { id: number; alumno: string; descripcion: string; vencimiento: string; saldo: number; vencido: boolean };
type Comprobante = { id: number; nombre_tipo: string; serie: string; numero: number; fecha_emision: string; total: number; estado_sunat: string; pdf_archivo_id: number | null };
type Datos = { cargos: Cargo[]; en_linea: { medios: string[]; nombres: Record<string, string>; pruebas: boolean } | null; comprobantes: Comprobante[] };
type Orden = { orden: string; monto: number; estado: string; medio: string | null; comprobante: { id: number; serie: string; numero: number; pdf_archivo_id: number | null } | null };

/** EDUN-73 · La familia ve lo que debe, paga en línea los cargos emitidos y descarga sus boletas. */
export function MisPagos() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error } = useCarga(() => pedir<Datos>('/familia/pagos'));
  const accion = useAccion();
  const [elegidos, setElegidos] = useState<number[]>([]);
  const total = datos?.cargos.filter((c) => elegidos.includes(c.id)).reduce((s, c) => s + c.saldo, 0) ?? 0;
  const descargar = (id: number) => accion.ejecutar(async () => { window.open((await pedir<{ url: string }>(`/familia/comprobantes/${id}`)).url, '_blank', 'noopener'); });

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Mis pagos" descripcion="Cuotas emitidas por pagar y tus comprobantes. Pagas el monto exacto: la comisión de la pasarela la asume el colegio." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {datos && (
        <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
          <div className="tarjeta">
            <h2>Por pagar</h2>
            <div className="tabla-caja">
              <table className="tabla">
                <thead><tr>{datos.en_linea && <th />}<th>Hijo(a)</th><th>Concepto</th><th>Vence</th><th>Saldo</th></tr></thead>
                <tbody>
                  {datos.cargos.map((c) => (
                    <tr key={c.id}>
                      {datos.en_linea && <td><input type="checkbox" aria-label={`Pagar ${c.descripcion}`} checked={elegidos.includes(c.id)} onChange={(e) => setElegidos(e.target.checked ? [...elegidos, c.id] : elegidos.filter((x) => x !== c.id))} /></td>}
                      <td>{c.alumno}</td><td>{c.descripcion} {c.vencido && <span className="insignia insignia-rojo">Vencido</span>}</td><td>{fecha(c.vencimiento)}</td><td>{soles(c.saldo)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {datos.cargos.length === 0 && <p className="vacio">No tienes cuotas pendientes. ¡Gracias!</p>}
            </div>
            {datos.en_linea ? (
              <>
                <p className="ayuda">Paga con {datos.en_linea.medios.map((m) => datos.en_linea!.nombres[m]).join(', ')}.{datos.en_linea.pruebas ? ' (El colegio está en modo pruebas.)' : ''}</p>
                <div className="acciones">
                  <button type="button" className="boton" disabled={!elegidos.length || accion.enviando} onClick={() => accion.ejecutar(async () => {
                    window.location.assign((await pedir<{ url: string }>('/familia/pagos/ordenes', { metodo: 'POST', cuerpo: { cargos: elegidos } })).url);
                  })}>Pagar {soles(total)} en línea</button>
                </div>
              </>
            ) : datos.cargos.length > 0 && <p className="ayuda">El colegio aún no tiene pagos en línea: paga en caja o por el banco.</p>}
          </div>
          <div className="tarjeta">
            <h2>Mis comprobantes</h2>
            <div className="tabla-caja">
              <table className="tabla">
                <thead><tr><th>Comprobante</th><th>Fecha</th><th>Total</th><th /></tr></thead>
                <tbody>
                  {datos.comprobantes.map((c) => (
                    <tr key={c.id}>
                      <td><b>{c.serie}-{c.numero}</b> <small className="ayuda">{c.nombre_tipo}</small></td><td>{fecha(c.fecha_emision)}</td><td>{soles(c.total)}</td>
                      <td>{c.pdf_archivo_id && <button type="button" className="boton boton-chico boton-borde" onClick={() => descargar(c.id)}>Descargar PDF</button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {datos.comprobantes.length === 0 && <p className="vacio">Aún no tienes comprobantes.</p>}
            </div>
          </div>
        </div>
      )}
    </>
  );
}

/** Página de pago de la pasarela simulada (servidor de pruebas). En producción la familia ve la de la pasarela. */
export function PagoSimulado({ orden }: { orden: string }) {
  const { pedir } = useSesion();
  const { datos, setDatos, error } = useCarga(() => pedir<Orden>(`/familia/pagos/ordenes/${encodeURIComponent(orden)}`), [orden]);
  const accion = useAccion();
  const [medio, setMedio] = useState('yape');
  const pagar = (resultado: 'pagada' | 'fallida') => accion.ejecutar(async () => {
    const r = await pedir<{ estado: string; orden: Orden }>(`/familia/pagos/ordenes/${encodeURIComponent(orden)}/simulacion`, { metodo: 'POST', cuerpo: { resultado, medio } });
    setDatos(r.orden);
  });
  return (
    <>
      <Titulo migas="Pasarela de pagos (simulada)" titulo="Confirmar pago" descripcion={`Orden ${orden}`} />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {datos && (
        <div className="tarjeta" style={{ maxWidth: 480 }}>
          <h2>{soles(datos.monto)}</h2>
          {datos.estado === 'pagada' ? (
            <>
              <Aviso tipo="ok">Pago confirmado por la pasarela{datos.comprobante ? `. Boleta ${datos.comprobante.serie}-${datos.comprobante.numero}; también te llegará por correo.` : '.'}</Aviso>
              <a className="boton" href="#/mis-pagos">Volver a mis pagos</a>
            </>
          ) : (
            <>
              <Aviso tipo="ambar">Pasarela de prueba: no se cobra dinero real.</Aviso>
              {datos.estado === 'fallida' && <Aviso tipo="error">El pago no se completó. Puedes intentarlo de nuevo.</Aviso>}
              <div className="filtros">
                {['yape', 'plin', 'tarjeta'].map((m) => <label key={m} className="casilla"><input type="radio" name="medio" checked={medio === m} onChange={() => setMedio(m)} /> {m === 'tarjeta' ? 'Tarjeta' : m === 'yape' ? 'Yape' : 'Plin'}</label>)}
              </div>
              <div className="acciones">
                <button type="button" className="boton" disabled={accion.enviando} onClick={() => pagar('pagada')}>Pagar</button>
                <button type="button" className="boton boton-borde" disabled={accion.enviando} onClick={() => pagar('fallida')}>Simular rechazo</button>
              </div>
            </>
          )}
        </div>
      )}
    </>
  );
}
