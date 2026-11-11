import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { soles } from './tipos';

type Cuota = { id: number; numero: number; vencimiento: string; monto: number; pagado: number; estado: string };
type Convenio = { id: number; numero: string; estado: string; apoderado: string; alumno: string; monto_total: number; pagado: number; saldo: number; numero_cuotas: number;
  firmado_at: string | null; firma_canal: string | null; aprobado_at: string | null; cuotas: Cuota[]; cargos: { id: number; descripcion: string; saldo_incluido: number }[] };
type Elegible = { id: number; alumno: string; descripcion: string; vencimiento: string; saldo: number };
type FamiliaMorosa = { apoderado_id: number; apoderado: string; deuda: number };

const ESTADO: Record<string, string> = { borrador: 'insignia-ambar', vigente: 'insignia-ok', cumplido: 'insignia-ok', incumplido: 'insignia-rojo', anulado: 'insignia-rojo' };

function TablaCuotas({ cuotas }: { cuotas: Cuota[] }) {
  return (
    <table className="tabla"><thead><tr><th>N.°</th><th>Vence</th><th>Monto</th><th>Pagado</th><th>Estado</th></tr></thead><tbody>
      {cuotas.map((q) => <tr key={q.numero}><td>{q.numero}</td><td>{fecha(q.vencimiento)}</td><td>{soles(q.monto)}</td><td>{soles(q.pagado)}</td><td><span className={`insignia ${q.estado === 'pagada' ? 'insignia-ok' : q.estado === 'vencida' ? 'insignia-rojo' : ''}`}>{q.estado}</span></td></tr>)}
    </tbody></table>
  );
}

function NuevoConvenio({ alCrear }: { alCrear: () => void }) {
  const { pedir } = useSesion();
  const familias = useCarga(() => pedir<{ data: FamiliaMorosa[] }>('/pagos/morosidad'));
  const [apoderado, setApoderado] = useState<number | ''>('');
  const elegibles = useCarga(() => (apoderado ? pedir<{ data: Elegible[] }>(`/pagos/convenios/elegibles/${apoderado}`) : Promise.resolve({ data: [] })), [apoderado]);
  const [cargos, setCargos] = useState<number[]>([]);
  const [cuotas, setCuotas] = useState(3);
  const [primera, setPrimera] = useState('');
  const [simulacion, setSimulacion] = useState<Cuota[] | null>(null);
  const accion = useAccion();
  const total = Math.round((elegibles.datos?.data.filter((c) => cargos.includes(c.id)).reduce((s, c) => s + c.saldo, 0) ?? 0) * 100) / 100;
  return (
    <>
      <Campo etiqueta="Familia con deuda vencida" ancho>
        <select value={apoderado} onChange={(e) => { setApoderado(Number(e.target.value) || ''); setCargos([]); setSimulacion(null); }}>
          <option value="">Elige</option>{familias.datos?.data.map((f) => <option key={f.apoderado_id} value={f.apoderado_id}>{f.apoderado} · {soles(f.deuda)}</option>)}
        </select>
      </Campo>
      {elegibles.datos?.data.map((c) => (
        <label key={c.id} className="casilla"><input type="checkbox" checked={cargos.includes(c.id)} onChange={(e) => { setCargos(e.target.checked ? [...cargos, c.id] : cargos.filter((x) => x !== c.id)); setSimulacion(null); }} /> {c.alumno} · {c.descripcion} · venció {fecha(c.vencimiento)} · {soles(c.saldo)}</label>
      ))}
      {apoderado && elegibles.datos?.data.length === 0 && <p className="ayuda">Esta familia no tiene cargos vencidos libres (pueden estar en otro convenio).</p>}
      <div className="campos">
        <Campo etiqueta="Cuotas (2 a 12)"><input type="number" min={2} max={12} value={cuotas} onChange={(e) => { setCuotas(Number(e.target.value)); setSimulacion(null); }} /></Campo>
        <Campo etiqueta="Vence la primera"><input type="date" value={primera} onChange={(e) => { setPrimera(e.target.value); setSimulacion(null); }} /></Campo>
      </div>
      <p className="ayuda">Total {soles(total)}, sin intereses ni mora. Los pagos se aplican a los cargos originales.</p>
      {simulacion && <TablaCuotas cuotas={simulacion} />}
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="button" className="boton boton-borde" disabled={!total || !primera} onClick={() => accion.ejecutar(async () => setSimulacion((await pedir<{ cuotas: Cuota[] }>('/pagos/convenios/simulacion', { metodo: 'POST', cuerpo: { total, cuotas, primera } })).cuotas.map((q) => ({ ...q, id: q.numero, pagado: 0, estado: 'pendiente' }))))}>Simular</button>
        <button type="button" className="boton" disabled={!simulacion || accion.enviando} onClick={() => accion.ejecutar(async () => { await pedir('/pagos/convenios', { metodo: 'POST', cuerpo: { apoderado_id: apoderado, cargos, cuotas, primera } }); alCrear(); })}>Crear convenio (borrador)</button>
      </div>
    </>
  );
}

/** EDUN-84 / EDUN-85 / EDUN-86 · PAG-09 Convenios de fraccionamiento. */
export function Convenios() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Convenio[] }>('/pagos/convenios'));
  const accion = useAccion();
  const [nuevo, setNuevo] = useState(false);
  const [abierto, setAbierto] = useState<Convenio | null>(null);
  const acta = (id: number) => accion.ejecutar(async () => { window.open((await pedir<{ url: string }>(`/pagos/convenios/${id}/acta`)).url, '_blank', 'noopener'); });
  const firmaPapel = (c: Convenio, f: File) => accion.ejecutar(async () => {
    const cuerpo = new FormData();
    cuerpo.append('archivo', f);
    cuerpo.append('confidencial', '1');
    const archivo = (await pedir<{ id: number }>('/archivos', { metodo: 'POST', cuerpo })).id;
    setAbierto(await pedir<Convenio>(`/pagos/convenios/${c.id}/firma`, { metodo: 'POST', cuerpo: { archivo_id: archivo } }));
    recargar();
  }, 'Acta firmada adjunta.');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Convenios de fraccionamiento" descripcion="Solo deuda vencida, de 2 a 12 cuotas sin intereses. Lo firma el apoderado y lo aprueba dirección.">
        {puede(colegio, 'pag.convenios.crear') && <button type="button" className="boton" onClick={() => setNuevo(true)}>+ Convenio</button>}
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tarjeta">
        <div className="tabla-caja">
          <table className="tabla"><thead><tr><th>Convenio</th><th>Familia</th><th>Total</th><th>Pagado</th><th>Estado</th><th /></tr></thead><tbody>
            {datos?.data.map((c) => (
              <tr key={c.id}>
                <td><b>{c.numero}</b><br /><small className="ayuda">{c.numero_cuotas} cuotas</small></td><td>{c.apoderado}<br /><small className="ayuda">{c.alumno}</small></td>
                <td>{soles(c.monto_total)}</td><td>{soles(c.pagado)}</td>
                <td><span className={`insignia ${ESTADO[c.estado]}`}>{c.estado}</span>{c.estado === 'borrador' && <><br /><small className="ayuda">{c.firmado_at ? `firmado (${c.firma_canal})` : 'sin firma'}</small></>}</td>
                <td><button type="button" className="boton boton-chico boton-borde" onClick={() => setAbierto(c)}>Ver</button></td>
              </tr>
            ))}
          </tbody></table>
          {datos?.data.length === 0 && <p className="vacio">No hay convenios.</p>}
        </div>
      </div>
      {nuevo && <Dialogo titulo="Nuevo convenio" alCerrar={() => setNuevo(false)}><NuevoConvenio alCrear={() => { setNuevo(false); recargar(); accion.setOk('Convenio creado en borrador: falta la firma y la aprobación.'); }} /></Dialogo>}
      {abierto && (
        <Dialogo titulo={`${abierto.numero} · ${abierto.apoderado}`} alCerrar={() => setAbierto(null)}>
          <p>Saldo {soles(abierto.saldo)} de {soles(abierto.monto_total)} · <span className={`insignia ${ESTADO[abierto.estado]}`}>{abierto.estado}</span></p>
          <TablaCuotas cuotas={abierto.cuotas} />
          <p className="ayuda">Cargos: {abierto.cargos.map((g) => `${g.descripcion} (${soles(g.saldo_incluido)})`).join(', ')}</p>
          {abierto.firmado_at && <p className="ayuda">Firmado {fechaHora(abierto.firmado_at)} ({abierto.firma_canal}){abierto.aprobado_at ? ` · aprobado ${fechaHora(abierto.aprobado_at)}` : ''}</p>}
          <div className="acciones">
            <button type="button" className="boton boton-borde" onClick={() => acta(abierto.id)}>Acta PDF</button>
            {abierto.estado === 'borrador' && !abierto.firmado_at && puede(colegio, 'pag.convenios.crear') && (
              <label className="boton boton-borde">Adjuntar acta firmada<input type="file" hidden accept=".pdf,image/*" onChange={(e) => e.target.files?.[0] && firmaPapel(abierto, e.target.files[0])} /></label>
            )}
            {abierto.estado === 'borrador' && puede(colegio, 'pag.convenios.aprobar') && <button type="button" className="boton" disabled={!abierto.firmado_at} onClick={() => accion.ejecutar(async () => { setAbierto(await pedir<Convenio>(`/pagos/convenios/${abierto.id}/aprobacion`, { metodo: 'POST' })); recargar(); }, 'Convenio aprobado: ya está vigente.')}>Aprobar</button>}
            {['borrador', 'vigente'].includes(abierto.estado) && puede(colegio, 'pag.convenios.anular') && <button type="button" className="boton boton-borde" onClick={() => { const m = window.prompt('Motivo de la anulación'); if (m && m.trim().length >= 5) accion.ejecutar(async () => { setAbierto(await pedir<Convenio>(`/pagos/convenios/${abierto.id}/anulacion`, { metodo: 'POST', cuerpo: { motivo: m } })); recargar(); }); }}>Anular</button>}
          </div>
          <Aviso tipo="error">{accion.error}</Aviso>
        </Dialogo>
      )}
    </>
  );
}

/** EDUN-85 / EDUN-87 · La familia firma su convenio, ve cuotas, pagado y saldo, y paga la cuota. */
export function MisConvenios() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Convenio[] }>('/familia/convenios'));
  const accion = useAccion();
  const [acepto, setAcepto] = useState<Record<number, boolean>>({});
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Mis convenios" descripcion="Tus cuotas, lo pagado y el saldo. Puedes pagar cada cuota en línea." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {datos?.data.length === 0 && <p className="vacio">No tienes convenios.</p>}
      <div style={{ display: 'grid', gap: 16, gridTemplateColumns: 'minmax(0, 1fr)' }}>
        {datos?.data.map((c) => (
          <div key={c.id} className="tarjeta">
            <h2>{c.numero} <span className={`insignia ${ESTADO[c.estado]}`}>{c.estado}</span></h2>
            <p>Pagado {soles(c.pagado)} · saldo <b>{soles(c.saldo)}</b> de {soles(c.monto_total)}</p>
            <TablaCuotas cuotas={c.cuotas} />
            {c.estado === 'borrador' && !c.firmado_at && (
              <>
                <label className="casilla"><input type="checkbox" checked={!!acepto[c.id]} onChange={(e) => setAcepto({ ...acepto, [c.id]: e.target.checked })} /> Leí el acta y acepto el convenio</label>
                <div className="acciones"><button type="button" className="boton" disabled={!acepto[c.id]} onClick={() => accion.ejecutar(async () => { await pedir(`/familia/convenios/${c.id}/firma`, { metodo: 'POST', cuerpo: { acepto: true } }); recargar(); })}>Firmar convenio</button></div>
              </>
            )}
            {c.estado === 'borrador' && c.firmado_at && <p className="ayuda">Firmado: falta la aprobación de dirección.</p>}
            {c.estado === 'vigente' && (() => {
              const proxima = c.cuotas.find((q) => q.estado !== 'pagada');
              return proxima && (
                <div className="acciones"><button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => {
                  window.location.assign((await pedir<{ url: string }>(`/familia/convenios/${c.id}/cuotas/${proxima.numero}/pago`, { metodo: 'POST' })).url);
                })}>Pagar cuota {proxima.numero} ({soles(proxima.monto - proxima.pagado)})</button></div>
              );
            })()}
          </div>
        ))}
      </div>
    </>
  );
}
