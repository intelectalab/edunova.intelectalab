import { useState } from 'react';
import { apiArchivo, guardarArchivo } from '../api/cliente';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Pestanas, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from '../adm/anios';
import { SelectorAnio } from '../adm/SelectorAnio';
import type { Periodo } from './tipos';

type EstadoLibreta = { id: number; alumno_id: number; alumno: string; libreta: { id: number; publicada_at: string | null; vista_por_padre_at: string | null } | null };
type Nota = { final: string | null; anual: string | null; recuperacion: string | null };
type Acta = {
  seccion: { id: number; grado: string; nombre: string; anio: number; anio_id: number; escala: string };
  competencias: { id: number; numero: number; area: string }[];
  filas: { alumno_id: number; alumno: string; notas: Record<string, Nota>; promedio: number | null; desaprobadas: number; situacion: string }[];
  merito: { puesto: number; alumno: string; promedio: number }[];
};

function GestionLibretas({ seccion, periodos }: { seccion: number; periodos: Periodo[] }) {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [eleccion, setPeriodo] = useState<number | null>(null);
  const periodo = eleccion ?? periodos.find((p) => p.estado !== 'programado')?.id ?? periodos[0]?.id ?? null;
  const { datos, setDatos, error } = useCarga(() => (periodo ? pedir<{ data: EstadoLibreta[] }>(`/libretas?seccion_id=${seccion}&periodo_id=${periodo}`) : Promise.resolve({ data: [] })), [seccion, periodo]);
  const accion = useAccion();
  const generadas = datos?.data.filter((d) => d.libreta).length ?? 0;
  const sinPublicar = datos?.data.filter((d) => d.libreta && !d.libreta.publicada_at).length ?? 0;
  return (
    <div className="tarjeta">
      <div className="filtros" style={{ alignItems: 'end' }}>
        <Campo etiqueta="Periodo"><select value={periodo ?? ''} onChange={(e) => setPeriodo(Number(e.target.value))}>{periodos.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}</select></Campo>
        {puede(colegio, 'cal.libretas.generar') && <button type="button" className="boton boton-borde" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => {
          const r = await pedir<{ generadas: number; data: EstadoLibreta[] }>('/libretas', { metodo: 'POST', cuerpo: { seccion_id: seccion, periodo_id: periodo } });
          setDatos({ data: r.data });
          accion.setOk(`${r.generadas} libretas generadas. Revísalas y publícalas.`);
        })}>{generadas ? 'Regenerar libretas' : 'Generar libretas'}</button>}
        {puede(colegio, 'cal.libretas.publicar') && <button type="button" className="boton" disabled={!sinPublicar || accion.enviando} onClick={() => accion.ejecutar(async () => {
          const r = await pedir<{ publicadas: number; data: EstadoLibreta[] }>('/libretas/publicacion', { metodo: 'POST', cuerpo: { seccion_id: seccion, periodo_id: periodo } });
          setDatos({ data: r.data });
          accion.setOk(`${r.publicadas} libretas publicadas en la app. Las familias recibieron el aviso.`);
        })}>Publicar {sinPublicar || ''}</button>}
      </div>
      <p className="ayuda">La libreta se publica aunque exista deuda. Regenerar quita la publicación anterior.</p>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tabla-caja">
        <table className="tabla">
          <thead><tr><th>Alumno</th><th>Libreta</th><th>Publicada</th><th>Vista por la familia</th></tr></thead>
          <tbody>
            {datos?.data.map((d) => (
              <tr key={d.id}>
                <td>{d.alumno}</td><td>{d.libreta ? 'Generada' : '—'}</td>
                <td>{d.libreta?.publicada_at ? fechaHora(d.libreta.publicada_at) : '—'}</td>
                <td>{d.libreta?.vista_por_padre_at ? <span className="insignia insignia-ok">{fechaHora(d.libreta.vista_por_padre_at)}</span> : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function ActaSeccion({ seccion }: { seccion: number }) {
  const { pedir, estado, token } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, setDatos, error } = useCarga(() => pedir<Acta>(`/actas/${seccion}`), [seccion]);
  const accion = useAccion();
  const [rec, setRec] = useState<Record<string, string>>({});
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;
  const recupera = puede(colegio, 'cal.notas.aprobar');
  return (
    <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
      <div className="tarjeta">
        <div className="titulo-fila">
          <h2>Acta {datos.seccion.grado} {datos.seccion.nombre} {datos.seccion.anio}</h2>
          <div style={{ display: 'flex', gap: 6 }}>
            {(['pdf', 'xlsx'] as const).map((f) => (
              <button key={f} type="button" className="boton boton-chico boton-borde" onClick={() => accion.ejecutar(async () => guardarArchivo(await apiArchivo(`/actas/${seccion}/${f}`, token), `acta-${datos.seccion.grado}-${datos.seccion.nombre}.${f}`))}>{f === 'pdf' ? 'PDF' : 'Excel'}</button>
            ))}
          </div>
        </div>
        <Aviso tipo="error">{accion.error}</Aviso>
        <div className="tabla-caja">
          <table className="tabla">
            <thead><tr><th>Alumno</th>{datos.competencias.map((k) => <th key={k.id} title={k.area}>{k.area.slice(0, 10)} C{k.numero}</th>)}<th>Promedio</th><th>Situación final</th></tr></thead>
            <tbody>
              {datos.filas.map((f) => (
                <tr key={f.alumno_id}>
                  <td>{f.alumno}</td>
                  {datos.competencias.map((k) => {
                    const n = f.notas[k.id];
                    const clave = `${f.alumno_id}-${k.id}`;
                    const desaprobada = n?.anual === 'C' || (datos.seccion.escala === 'vigesimal' && n?.anual !== null && Number(n?.anual) < 11);
                    return (
                      <td key={k.id}>
                        <b>{n?.final ?? '—'}</b>{n?.recuperacion && <small className="ayuda"> (rec.)</small>}
                        {recupera && desaprobada && (
                          <input aria-label="Nota de recuperación" maxLength={2} style={{ width: 44, marginLeft: 4 }} placeholder="rec" value={rec[clave] ?? ''}
                            onChange={(e) => setRec({ ...rec, [clave]: e.target.value.toUpperCase() })}
                            onBlur={() => rec[clave] && accion.ejecutar(async () => {
                              setDatos(await pedir<Acta>('/recuperacion', { metodo: 'PUT', cuerpo: { alumno_id: f.alumno_id, anio_escolar_id: datos.seccion.anio_id, competencia_id: k.id, valor: rec[clave], seccion_id: seccion } }));
                              setRec({ ...rec, [clave]: '' });
                            })} />
                        )}
                      </td>
                    );
                  })}
                  <td>{f.promedio ?? '—'}</td>
                  <td><span className={`insignia ${f.situacion === 'Promovido' ? 'insignia-ok' : f.situacion === 'Requiere recuperación' ? 'insignia-ambar' : 'insignia-rojo'}`}>{f.situacion}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {recupera && <p className="ayuda">En las competencias desaprobadas escribe la nota de recuperación pedagógica; la situación final se actualiza sola.</p>}
      </div>
      <div className="tarjeta">
        <h2>Cuadro de méritos</h2>
        <ol>{datos.merito.slice(0, 10).map((m) => <li key={m.puesto}>{m.alumno} · {m.promedio}</li>)}</ol>
        {datos.merito.length === 0 && <p className="vacio">Aún no hay notas.</p>}
      </div>
    </div>
  );
}

/** EDUN-99 / EDUN-104 / EDUN-105 · Libretas, acta, méritos y recuperación por sección. */
export function LibretasYActas() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { anios, anio, setAnio } = useAnioEscolar();
  const secciones = useCarga(() => (anio ? pedir<{ data: { id: number; nombre: string }[] }>(`/asistencia/secciones?anio_escolar_id=${anio}`) : Promise.resolve({ data: [] })), [anio]);
  const periodos = useCarga(() => (anio ? pedir<{ periodos: Periodo[] }>(`/notas/cargas?anio_escolar_id=${anio}`) : Promise.resolve({ periodos: [] })), [anio]);
  const [eleccion, setSeccion] = useState<number | null>(null);
  const seccion = eleccion ?? secciones.datos?.data[0]?.id ?? null;
  const [vista, setVista] = useState<'libretas' | 'acta'>('libretas');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Académico`} titulo="Libretas y actas" descripcion="Libreta por alumno y periodo, publicación en la app, acta anual con recuperación y cuadro de méritos." />
      <div className="filtros">
        {anios.length > 0 && <SelectorAnio anios={anios} anio={anio} alCambiar={(id) => { setAnio(id); setSeccion(null); }} />}
        <Campo etiqueta="Sección"><select value={seccion ?? ''} onChange={(e) => setSeccion(Number(e.target.value))}>{secciones.datos?.data.map((s) => <option key={s.id} value={s.id}>{s.nombre}</option>)}</select></Campo>
      </div>
      {seccion && (
        <>
          <Pestanas opciones={[['libretas', 'Libretas'], ['acta', 'Acta y méritos']]} actual={vista} alCambiar={setVista} />
          <div style={{ marginTop: 12 }}>{vista === 'libretas' ? <GestionLibretas seccion={seccion} periodos={periodos.datos?.periodos ?? []} /> : <ActaSeccion seccion={seccion} />}</div>
        </>
      )}
    </>
  );
}
