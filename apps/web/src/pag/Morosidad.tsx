import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { soles } from './tipos';

type Familia = { apoderado_id: number; apoderado: string; celular: string | null; alumnos: string[]; cargos: number; deuda: number; dias: number; tramo: string; ultima_gestion: string | null; compromiso: { monto: number; fecha: string } | null };
type Historial = { gestiones: { id: number; canal: string; resultado: string; detalle: string | null; created_at: string; por: string | null }[]; compromisos: { id: number; monto: number; fecha_compromiso: string; estado: string }[] };
type Datos = { data: Familia[]; resumen: Record<string, { familias: number; deuda: number }>; tramos: Record<string, string> };

/** EDUN-81 · PAG-08 Morosidad por tramos, gestiones, compromisos y recordatorio masivo. */
export function Morosidad() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [tramo, setTramo] = useState('');
  const { datos, error, recargar } = useCarga(() => pedir<Datos>(`/pagos/morosidad${tramo ? `?tramo=${tramo}` : ''}`), [tramo]);
  const accion = useAccion();
  const [abierta, setAbierta] = useState<Familia | null>(null);
  const historial = useCarga(() => (abierta ? pedir<Historial>(`/pagos/morosidad/${abierta.apoderado_id}`) : Promise.resolve(null)), [abierta?.apoderado_id]);
  const [g, setG] = useState({ canal: 'llamada', resultado: '', detalle: '', monto: '', fecha: '' });
  const gestiona = puede(colegio, 'pag.cobranza.gestionar');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Morosidad" descripcion="Deuda vencida por familia. La deuda nunca bloquea notas ni libretas.">
        {gestiona && <button type="button" className="boton boton-borde" onClick={() => window.confirm(`¿Enviar un recordatorio por app y correo a ${tramo ? `las familias del tramo ${datos?.tramos[tramo]}` : 'todas las familias con deuda'}?`) && accion.ejecutar(async () => {
          const r = await pedir<{ familias: number; enviados: number }>('/pagos/morosidad/recordatorio', { metodo: 'POST', cuerpo: { tramo: tramo || null, canales: ['app', 'correo'] } });
          accion.setOk(`Recordatorio enviado a ${r.enviados} de ${r.familias} familias.`);
        })}>Recordatorio masivo</button>}
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos && (
        <>
          <div className="rejilla">
            {Object.entries(datos.tramos).map(([k, v]) => (
              <button key={k} type="button" className="tarjeta" style={{ textAlign: 'left', cursor: 'pointer', outline: tramo === k ? '2px solid var(--en-primario)' : undefined }} onClick={() => setTramo(tramo === k ? '' : k)}>
                <div className="migas">{v}</div><h2>{soles(datos.resumen[k]?.deuda ?? 0)}</h2><p className="ayuda">{datos.resumen[k]?.familias ?? 0} familias</p>
              </button>
            ))}
          </div>
          <div className="tarjeta" style={{ marginTop: 14 }}>
            <div className="tabla-caja">
              <table className="tabla"><thead><tr><th>Familia</th><th>Alumnos</th><th>Deuda</th><th>Antigüedad</th><th>Última gestión</th><th>Compromiso</th><th /></tr></thead><tbody>
                {datos.data.map((f) => (
                  <tr key={f.apoderado_id}>
                    <td>{f.apoderado}<br /><small className="ayuda">{f.celular}</small></td><td>{f.alumnos.join(', ')}</td><td><b>{soles(f.deuda)}</b> <small className="ayuda">({f.cargos})</small></td>
                    <td><span className={`insignia ${f.tramo === '60+' ? 'insignia-rojo' : 'insignia-ambar'}`}>{f.dias} días</span></td>
                    <td>{f.ultima_gestion ? fechaHora(f.ultima_gestion) : '—'}</td><td>{f.compromiso ? `${soles(f.compromiso.monto)} al ${fecha(f.compromiso.fecha)}` : '—'}</td>
                    <td><button type="button" className="boton boton-chico boton-borde" onClick={() => setAbierta(f)}>Gestionar</button></td>
                  </tr>
                ))}
              </tbody></table>
              {datos.data.length === 0 && <p className="vacio">No hay deuda vencida.</p>}
            </div>
          </div>
        </>
      )}
      {abierta && (
        <Dialogo titulo={`${abierta.apoderado} · ${soles(abierta.deuda)}`} alCerrar={() => { setAbierta(null); recargar(); }}>
          {gestiona && (
            <>
              <div className="campos">
                <Campo etiqueta="Canal"><select value={g.canal} onChange={(e) => setG({ ...g, canal: e.target.value })}><option value="llamada">Llamada</option><option value="whatsapp">WhatsApp</option><option value="correo">Correo</option><option value="presencial">Presencial</option></select></Campo>
                <Campo etiqueta="Resultado"><input value={g.resultado} maxLength={200} onChange={(e) => setG({ ...g, resultado: e.target.value })} /></Campo>
                <Campo etiqueta="Compromiso: monto"><input type="number" min={0} step="0.01" value={g.monto} onChange={(e) => setG({ ...g, monto: e.target.value })} /></Campo>
                <Campo etiqueta="Compromiso: fecha"><input type="date" value={g.fecha} onChange={(e) => setG({ ...g, fecha: e.target.value })} /></Campo>
              </div>
              <div className="acciones"><button type="button" className="boton" disabled={!g.resultado.trim() || accion.enviando} onClick={() => accion.ejecutar(async () => {
                historial.setDatos(await pedir<Historial>(`/pagos/morosidad/${abierta.apoderado_id}/gestiones`, { metodo: 'POST', cuerpo: { canal: g.canal, resultado: g.resultado, detalle: g.detalle || null, monto: g.monto ? Number(g.monto) : null, fecha: g.fecha || null } }));
                setG({ canal: 'llamada', resultado: '', detalle: '', monto: '', fecha: '' });
              })}>Registrar gestión</button></div>
            </>
          )}
          <Aviso tipo="error">{accion.error}</Aviso>
          <h3>Historial</h3>
          {historial.datos?.compromisos.map((c) => <p key={`c${c.id}`}><span className={`insignia ${c.estado === 'vigente' ? 'insignia-ambar' : c.estado === 'cumplido' ? 'insignia-ok' : 'insignia-rojo'}`}>Compromiso {c.estado}</span> {soles(c.monto)} al {fecha(c.fecha_compromiso)}</p>)}
          {historial.datos?.gestiones.map((x) => <p key={x.id}><small className="ayuda">{fechaHora(x.created_at)} · {x.canal} · {x.por}</small><br />{x.resultado}</p>)}
        </Dialogo>
      )}
    </>
  );
}
