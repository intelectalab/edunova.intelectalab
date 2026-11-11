import { useState } from 'react';
import { apiArchivo, guardarArchivo } from '../api/cliente';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { soles } from './tipos';

type ArchivoBanco = { id: number; banco: string; direccion: string; lineas: number; conciliadas: number; estado: string; created_at: string; nombre_original: string | null };
type Linea = { id: number; numero_linea: number; codigo_recaudacion: string; monto: number; saldo: number | null; resultado: string; alumno: string | null; descripcion: string | null; operacion: string | null };
type PagoLinea = { id: number; orden: string; medio: string | null; monto: number; comision: number | null; neto: number | null; fecha_abono: string | null; conciliacion: string | null; conciliacion_detalle: string | null; created_at: string; excedente: number; excedente_resuelto_at: string | null; excedente_nota: string | null };
type Reporte = { anio: number; total: number; por_mes: { mes: string; monto: number }[]; por_concepto: { concepto: string; monto: number }[]; por_nivel: { nivel: string; monto: number }[]; proyeccion: { mes: string; esperado: number; cobrado: number; pendiente: number; porcentaje: number }[] };

const RESULTADO: Record<string, string> = { conciliado: 'insignia-ok', monto_distinto: 'insignia-ambar', sin_coincidencia: 'insignia-rojo', duplicado: 'insignia-rojo', comision_distinta: 'insignia-ambar', no_abonado: 'insignia-rojo', sin_registro: 'insignia-rojo' };

/** EDUN-79 / EDUN-80 · PAG-07 Recaudación BCP e Interbank. */
export function Recaudacion() {
  const { pedir, estado, token } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ data: ArchivoBanco[]; bancos: string[] }>('/pagos/recaudacion'));
  const accion = useAccion();
  const [banco, setBanco] = useState('BCP');
  const [archivo, setArchivo] = useState<File | null>(null);
  const [lineas, setLineas] = useState<{ id: number; data: Linea[] } | null>(null);
  const procesa = puede(colegio, 'pag.recaudacion.procesar');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Recaudación bancaria" descripcion="Envía al banco las deudas del mes y procesa el archivo de pagos: se concilia por código de recaudación y se emiten las boletas." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <Aviso tipo="ambar">Los formatos de BCP e Interbank se confirman con cada banco al afiliar al colegio (se ajustan en la configuración del servidor).</Aviso>
      {procesa && (
        <div className="tarjeta" style={{ marginBottom: 14 }}>
          <div className="filtros" style={{ alignItems: 'end' }}>
            <Campo etiqueta="Banco"><select value={banco} onChange={(e) => setBanco(e.target.value)}>{(datos?.bancos ?? ['BCP', 'INTERBANK']).map((b) => <option key={b} value={b}>{b}</option>)}</select></Campo>
            <button type="button" className="boton boton-borde" onClick={() => accion.ejecutar(async () => {
              guardarArchivo(await apiArchivo('/pagos/recaudacion/deudas', token, { metodo: 'POST', cuerpo: { banco } }), `${banco.toLowerCase()}-deudas.txt`);
              recargar();
            }, 'Archivo de deudas generado.')}>Generar archivo de deudas</button>
            <label className="boton boton-borde">{archivo ? archivo.name : 'Archivo de pagos del banco'}<input type="file" hidden accept=".txt,.csv" onChange={(e) => setArchivo(e.target.files?.[0] ?? null)} /></label>
            <button type="button" className="boton" disabled={!archivo || accion.enviando} onClick={() => accion.ejecutar(async () => {
              const cuerpo = new FormData();
              cuerpo.append('banco', banco);
              cuerpo.append('archivo', archivo!);
              const r = await pedir<{ id: number; lineas: number; conciliado: number; monto_distinto: number; sin_coincidencia: number; duplicado: number }>('/pagos/recaudacion/pagos', { metodo: 'POST', cuerpo });
              setArchivo(null);
              recargar();
              setLineas({ id: r.id, data: (await pedir<{ data: Linea[] }>(`/pagos/recaudacion/${r.id}`)).data });
              accion.setOk(`${r.lineas} líneas: ${r.conciliado} conciliadas, ${r.monto_distinto} con monto distinto, ${r.sin_coincidencia} sin coincidencia, ${r.duplicado} duplicadas.`);
            })}>Procesar pagos</button>
          </div>
        </div>
      )}
      <div className="tarjeta">
        <table className="tabla"><thead><tr><th>Fecha</th><th>Banco</th><th>Tipo</th><th>Líneas</th><th>Estado</th><th /></tr></thead><tbody>
          {datos?.data.map((a) => (
            <tr key={a.id}><td>{fechaHora(a.created_at)}</td><td>{a.banco}</td><td>{a.direccion}</td><td>{a.direccion === 'pagos' ? `${a.conciliadas} de ${a.lineas} conciliadas` : a.lineas}</td><td>{a.estado}</td>
              <td>{a.direccion === 'pagos' && <button type="button" className="boton boton-chico boton-borde" onClick={() => accion.ejecutar(async () => setLineas({ id: a.id, data: (await pedir<{ data: Linea[] }>(`/pagos/recaudacion/${a.id}`)).data }))}>Ver líneas</button>}</td></tr>
          ))}
        </tbody></table>
        {datos?.data.length === 0 && <p className="vacio">Aún no hay archivos.</p>}
      </div>
      {lineas && (
        <div className="tarjeta" style={{ marginTop: 14 }}>
          <h2>Líneas del archivo</h2>
          <table className="tabla"><thead><tr><th>Línea</th><th>Código</th><th>Alumno</th><th>Monto</th><th>Saldo en Edunova</th><th>Resultado</th></tr></thead><tbody>
            {lineas.data.map((l) => <tr key={l.id}><td>{l.numero_linea}</td><td><code>{l.codigo_recaudacion}</code></td><td>{l.alumno ?? '—'}<br /><small className="ayuda">{l.descripcion}</small></td><td>{soles(l.monto)}</td><td>{l.saldo !== null ? soles(l.saldo) : '—'}</td><td><span className={`insignia ${RESULTADO[l.resultado]}`}>{l.resultado.replace('_', ' ')}</span></td></tr>)}
          </tbody></table>
        </div>
      )}
    </>
  );
}

/** EDUN-89 · Conciliación de pagos en línea y comisiones con el reporte de abonos. */
export function Conciliacion() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [mes, setMes] = useState('');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: PagoLinea[]; totales: { monto: number; comision: number; neto: number } }>(`/pagos/conciliacion${mes ? `?mes=${mes}` : ''}`), [mes]);
  const accion = useAccion();
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Conciliación de pagos en línea" descripcion="Monto, comisión y neto de cada pago, comparados con el reporte de abonos de la pasarela (CSV: orden, monto, comision, neto, fecha_abono)." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="filtros" style={{ alignItems: 'end' }}>
        <Campo etiqueta="Mes"><input type="month" value={mes} onChange={(e) => setMes(e.target.value)} /></Campo>
        {puede(colegio, 'pag.pasarela.conciliar') && <label className="boton">Subir reporte de abonos<input type="file" hidden accept=".csv,.txt" onChange={(e) => {
          const f = e.target.files?.[0];
          if (f) accion.ejecutar(async () => {
            const cuerpo = new FormData();
            cuerpo.append('reporte', f);
            const r = await pedir<Record<string, number>>('/pagos/conciliacion', { metodo: 'POST', cuerpo });
            recargar();
            accion.setOk(Object.entries(r).map(([k, v]) => `${k.replace('_', ' ')}: ${v}`).join(' · '));
          });
        }} /></label>}
      </div>
      {datos && datos.data.some((t) => t.excedente > 0 && !t.excedente_resuelto_at) && (
        <Aviso tipo="ambar">Hay pagos en línea con excedente: la familia pagó un cargo que ya estaba pagado. Devuélvelo o aplícalo a otro cargo y márcalo como resuelto.</Aviso>
      )}
      {datos && (
        <div className="tarjeta">
          <p>Total {soles(datos.totales.monto)} · comisión {soles(datos.totales.comision)} · neto {soles(datos.totales.neto)}</p>
          <div className="tabla-caja">
            <table className="tabla"><thead><tr><th>Fecha</th><th>Orden</th><th>Medio</th><th>Monto</th><th>Comisión</th><th>Neto</th><th>Abono</th><th>Conciliación</th><th>Excedente</th></tr></thead><tbody>
              {datos.data.map((t) => <tr key={t.id}><td>{fechaHora(t.created_at)}</td><td><code>{t.orden}</code></td><td>{t.medio}</td><td>{soles(t.monto)}</td><td>{t.comision !== null ? soles(t.comision) : '—'}</td><td>{t.neto !== null ? soles(t.neto) : '—'}</td><td>{t.fecha_abono ?? '—'}</td>
                <td>{t.conciliacion ? <span className={`insignia ${RESULTADO[t.conciliacion]}`}>{t.conciliacion.replace('_', ' ')}</span> : '—'}{t.conciliacion_detalle && <><br /><small className="ayuda">{t.conciliacion_detalle}</small></>}</td>
                <td>{t.excedente > 0 ? (t.excedente_resuelto_at ? <><span className="insignia insignia-ok">{soles(t.excedente)} resuelto</span><br /><small className="ayuda">{t.excedente_nota}</small></> : <>
                  <span className="insignia insignia-ambar">{soles(t.excedente)} por resolver</span>
                  {puede(colegio, 'pag.pasarela.conciliar') && <><br /><button type="button" className="boton boton-borde boton-chico" onClick={() => {
                    const nota = window.prompt('¿Cómo se resolvió? (devolución, aplicado a otro cargo…)');
                    if (nota) accion.ejecutar(async () => {
                      await pedir(`/pagos/conciliacion/${t.id}/excedente`, { metodo: 'POST', cuerpo: { nota } });
                      recargar();
                      accion.setOk('Excedente marcado como resuelto.');
                    });
                  }}>Marcar resuelto</button></>}
                </>) : '—'}</td></tr>)}
            </tbody></table>
            {datos.data.length === 0 && <p className="vacio">No hay pagos en línea.</p>}
          </div>
        </div>
      )}
    </>
  );
}

/** EDUN-83 · Reportes financieros con exportación a Excel. */
export function ReportesFinancieros() {
  const { pedir, estado, token } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [anio, setAnio] = useState(2027);
  const { datos, error } = useCarga(() => pedir<Reporte>(`/pagos/reportes?anio=${anio}`), [anio]);
  const accion = useAccion();
  const max = Math.max(1, ...(datos?.por_mes.map((m) => m.monto) ?? [1]));
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Reportes financieros" descripcion="Ingresos por mes, concepto y nivel, y proyección de cobranza.">
        <div className="filtros" style={{ marginBottom: 0, alignItems: 'end' }}>
          <Campo etiqueta="Año"><input type="number" min={2020} max={2100} value={anio} onChange={(e) => setAnio(Number(e.target.value))} /></Campo>
          {puede(colegio, 'pag.reportes.exportar') && <button type="button" className="boton boton-borde" onClick={() => accion.ejecutar(async () => guardarArchivo(await apiArchivo(`/pagos/reportes/excel?anio=${anio}`, token), `reporte-financiero-${anio}.xlsx`))}>Excel</button>}
        </div>
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {datos && (
        <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
          <div className="tarjeta">
            <h2>Ingresos {datos.anio}: {soles(datos.total)}</h2>
            {datos.por_mes.map((m) => (
              <div key={m.mes} style={{ display: 'grid', gridTemplateColumns: '80px 1fr 110px', gap: 8, alignItems: 'center', margin: '4px 0' }}>
                <span>{m.mes}</span><div style={{ height: 14, background: 'var(--en-primario)', width: `${(m.monto / max) * 100}%`, borderRadius: 4 }} /><span style={{ textAlign: 'right' }}>{soles(m.monto)}</span>
              </div>
            ))}
            {datos.por_mes.length === 0 && <p className="vacio">Sin ingresos en el año.</p>}
          </div>
          <div className="rejilla">
            <div className="tarjeta"><h2>Por concepto</h2>{datos.por_concepto.map((c) => <p key={c.concepto} style={{ display: 'flex', justifyContent: 'space-between' }}><span>{c.concepto}</span><b>{soles(c.monto)}</b></p>)}</div>
            <div className="tarjeta"><h2>Por nivel</h2>{datos.por_nivel.map((n) => <p key={n.nivel} style={{ display: 'flex', justifyContent: 'space-between' }}><span>{n.nivel}</span><b>{soles(n.monto)}</b></p>)}</div>
          </div>
          <div className="tarjeta">
            <h2>Proyección de cobranza</h2>
            <table className="tabla"><thead><tr><th>Mes</th><th>Esperado</th><th>Cobrado</th><th>Pendiente</th><th>%</th></tr></thead><tbody>
              {datos.proyeccion.map((p) => <tr key={p.mes}><td>{p.mes}</td><td>{soles(p.esperado)}</td><td>{soles(p.cobrado)}</td><td>{soles(p.pendiente)}</td><td>{p.porcentaje} %</td></tr>)}
            </tbody></table>
          </div>
        </div>
      )}
    </>
  );
}
