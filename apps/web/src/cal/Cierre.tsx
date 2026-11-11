import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from '../adm/anios';
import { SelectorAnio } from '../adm/SelectorAnio';
import type { Periodo } from './tipos';

type Area = { carga_id: number; area: string; docente: string; total: number; llenas: number; c_sin_conclusion: number; avance: number };
type Seccion = { seccion_id: number; seccion: string; estado: string; cerrado_at: string | null; motivo_reapertura: string | null; completo: boolean; avance: number; areas: Area[] };
type Avance = { periodo: Periodo; secciones: Seccion[]; todas_cerradas: boolean };

const ESTADO: Record<string, [string, string]> = { registro: ['En registro', ''], revision: ['En revisión', 'insignia-ambar'], aprobado: ['Aprobado', 'insignia-ok'], cerrado: ['Cerrado', 'insignia-ok'], reabierto: ['Reabierto', 'insignia-ambar'] };

/** EDUN-97 / EDUN-98 · CAL-H07/H08 Revisión, cierre y reapertura del periodo. */
export function CierrePeriodo() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { anios, anio, setAnio } = useAnioEscolar();
  const periodos = useCarga(() => (anio ? pedir<{ periodos: Periodo[] }>(`/notas/cargas?anio_escolar_id=${anio}`) : Promise.resolve({ periodos: [] })), [anio]);
  const [eleccion, setPeriodo] = useState<number | null>(null);
  const periodo = eleccion ?? periodos.datos?.periodos.find((p) => p.estado === 'abierto')?.id ?? periodos.datos?.periodos[0]?.id ?? null;
  const { datos, setDatos, error } = useCarga(() => (periodo ? pedir<Avance>(`/notas/periodos/${periodo}/avance`) : Promise.resolve(null)), [periodo]);
  const accion = useAccion();
  const [reabrir, setReabrir] = useState<{ seccion: Seccion; motivo: string } | null>(null);

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Académico`} titulo="Revisión y cierre de notas" descripcion="Avance por sección y área. Cerrar una sección bloquea sus notas; reabrir exige motivo y queda en la auditoría.">
        <div className="filtros" style={{ marginBottom: 0 }}>
          {anios.length > 0 && <SelectorAnio anios={anios} anio={anio} alCambiar={(id) => { setAnio(id); setPeriodo(null); }} />}
          <Campo etiqueta="Periodo">
            <select value={periodo ?? ''} onChange={(e) => setPeriodo(Number(e.target.value))}>
              {periodos.datos?.periodos.map((p) => <option key={p.id} value={p.id}>{p.nombre}{p.estado === 'cerrado' ? ' (cerrado)' : ''}</option>)}
            </select>
          </Campo>
        </div>
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos && (
        <>
          <div className="acciones" style={{ justifyContent: 'flex-start' }}>
            <button type="button" className="boton boton-borde" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => {
              const r = await pedir<{ docentes: number; cursos: number }>(`/notas/periodos/${periodo}/recordatorio`, { metodo: 'POST' });
              accion.setOk(r.docentes ? `Recordatorio enviado a ${r.docentes} docentes (${r.cursos} cursos pendientes).` : 'No hay docentes con notas pendientes.');
            })}>Recordar a docentes pendientes</button>
            {datos.todas_cerradas && <span className="insignia insignia-ok">Periodo cerrado</span>}
          </div>
          <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'minmax(0, 1fr)' }}>
            {datos.secciones.map((s) => (
              <div key={s.seccion_id} className="tarjeta">
                <div className="titulo-fila">
                  <h2>{s.seccion} <span className={`insignia ${ESTADO[s.estado]?.[1] ?? ''}`}>{ESTADO[s.estado]?.[0] ?? s.estado}</span> <small className="ayuda">{s.avance} %</small></h2>
                  <div style={{ display: 'flex', gap: 6 }}>
                    {s.estado !== 'cerrado' && puede(colegio, 'cal.notas.cerrar') && (
                      <button type="button" className="boton boton-chico" disabled={!s.completo || accion.enviando} title={s.completo ? undefined : 'Faltan notas o conclusiones'}
                        onClick={() => window.confirm(`¿Cerrar ${s.seccion}? Las notas quedarán bloqueadas.`) && accion.ejecutar(async () => {
                          setDatos(await pedir<Avance>(`/notas/periodos/${periodo}/cierre`, { metodo: 'POST', cuerpo: { seccion_id: s.seccion_id } }));
                        }, `${s.seccion} cerrada.`)}>Cerrar sección</button>
                    )}
                    {s.estado === 'cerrado' && puede(colegio, 'cal.notas.reabrir') && (
                      <button type="button" className="boton boton-chico boton-borde" onClick={() => setReabrir({ seccion: s, motivo: '' })}>Reabrir</button>
                    )}
                  </div>
                </div>
                {s.cerrado_at && <p className="ayuda">Cerrada el {fechaHora(s.cerrado_at)}</p>}
                {s.motivo_reapertura && s.estado !== 'cerrado' && <p className="ayuda">Reabierta: {s.motivo_reapertura}</p>}
                <div className="tabla-caja">
                  <table className="tabla">
                    <thead><tr><th>Área</th><th>Docente</th><th>Avance</th><th>C sin conclusión</th></tr></thead>
                    <tbody>
                      {s.areas.map((a) => (
                        <tr key={a.carga_id}>
                          <td>{a.area}</td><td>{a.docente}</td>
                          <td><span className={`insignia ${a.avance === 100 ? 'insignia-ok' : a.avance >= 50 ? 'insignia-ambar' : 'insignia-rojo'}`}>{a.llenas} / {a.total} · {a.avance} %</span></td>
                          <td>{a.c_sin_conclusion ? <span className="insignia insignia-rojo">{a.c_sin_conclusion}</span> : '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            ))}
            {datos.secciones.length === 0 && <p className="vacio">No hay cursos con docente asignado en este año.</p>}
          </div>
        </>
      )}
      {reabrir && (
        <Dialogo titulo={`Reabrir ${reabrir.seccion.seccion}`} alCerrar={() => setReabrir(null)}>
          <p className="ayuda">La sección vuelve a revisión y los docentes podrán corregir. El motivo queda en la auditoría.</p>
          <Campo etiqueta="Motivo" ancho><input value={reabrir.motivo} maxLength={500} onChange={(e) => setReabrir({ ...reabrir, motivo: e.target.value })} /></Campo>
          <div className="acciones">
            <button type="button" className="boton" disabled={reabrir.motivo.trim().length < 10 || accion.enviando} onClick={() => accion.ejecutar(async () => {
              setDatos(await pedir<Avance>(`/notas/periodos/${periodo}/reapertura`, { metodo: 'POST', cuerpo: { seccion_id: reabrir.seccion.seccion_id, motivo: reabrir.motivo } }));
              setReabrir(null);
            }, 'Sección reabierta: vuelve a revisión.')}>Reabrir</button>
          </div>
        </Dialogo>
      )}
    </>
  );
}
