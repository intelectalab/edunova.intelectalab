import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import type { Curso } from './Aula';

type Foro = { id: number; titulo: string; mensajes: number; created_at: string };
type Mensaje = { id: number; contenido: string; respuesta_a: number | null; oculto: boolean; created_at: string; autor: string; mio: boolean };
type Clase = { id: number; titulo: string; inicio: string; plataforma: string; url: string; grabacion_url: string | null };
type FilaSeguimiento = { alumno_id: number; alumno: string; tiene_cuenta: boolean; avance: number; minutos: number; ultima_actividad: string | null; video_pct: number | null; tareas_entregadas: number; tareas_tardias: number; cuestionarios_rendidos: number; promedio_cuestionarios: number | null; observaciones: number };
const PLATAFORMA: Record<string, string> = { meet: 'Google Meet', zoom: 'Zoom', teams: 'Microsoft Teams', otro: 'Otro' };

/** EDUN-131 / EDUN-132 · Foros del curso y clases en vivo (enlace externo, recordatorio y grabación). */
export function ForosClases({ curso: c }: { curso: Curso }) {
  const { pedir } = useSesion();
  const docente = c.rol === 'docente';
  const foros = useCarga(() => pedir<{ data: Foro[] }>(`/aula/cursos/${c.id}/foros`), [c.id]);
  const clases = useCarga(() => pedir<{ data: Clase[] }>(`/aula/cursos/${c.id}/clases`), [c.id]);
  const [foro, setForo] = useState<Foro | null>(null);
  const [clase, setClase] = useState<Partial<Clase> | null>(null);
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{foros.error ?? clases.error ?? accion.error}</Aviso>
      <div className="tarjeta">
        <div className="titulo-fila"><h2>Clases en vivo</h2>{docente && <button type="button" className="boton" onClick={() => setClase({ plataforma: 'meet' })}>Programar clase</button>}</div>
        {(clases.datos?.data ?? []).length === 0 ? <p className="vacio">No hay clases programadas.</p> : <ul className="lista-simple">{clases.datos!.data.map((x) => (
          <li key={x.id}><b>{x.titulo}</b> · {fechaHora(x.inicio)} · {PLATAFORMA[x.plataforma]} · <a href={x.url} target="_blank" rel="noopener noreferrer">Entrar</a>
            {x.grabacion_url && <> · <a href={x.grabacion_url} target="_blank" rel="noopener noreferrer">Ver grabación</a></>}
            {docente && <> <button type="button" className="enlace" onClick={() => setClase(x)}>Editar</button></>}</li>
        ))}</ul>}
      </div>
      <div className="tarjeta">
        <div className="titulo-fila"><h2>Foros</h2>{docente && <button type="button" className="boton boton-borde" onClick={() => {
          const titulo = window.prompt('Tema del foro:');
          if (titulo?.trim()) void accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/foros`, { metodo: 'POST', cuerpo: { titulo } }); foros.recargar(); });
        }}>Nuevo foro</button>}</div>
        {(foros.datos?.data ?? []).length === 0 ? <p className="vacio">Sin foros.</p> : <ul className="lista-simple">{foros.datos!.data.map((f) => <li key={f.id}><button type="button" className="enlace" onClick={() => setForo(f)}>{f.titulo}</button> · {f.mensajes} mensaje(s)</li>)}</ul>}
      </div>
      {foro && <ForoAbierto curso={c} foro={foro} alCerrar={() => { setForo(null); foros.recargar(); }} />}
      {clase && (
        <Dialogo titulo={clase.id ? 'Editar clase' : 'Programar clase en vivo'} alCerrar={() => setClase(null)}>
          <Aviso tipo="error">{accion.error}</Aviso>
          <div className="campos">
            <Campo etiqueta="Título"><input value={clase.titulo ?? ''} onChange={(e) => setClase({ ...clase, titulo: e.target.value })} /></Campo>
            <Campo etiqueta="Inicio"><input type="datetime-local" value={clase.inicio ? new Date(clase.inicio).toLocaleString('sv-SE').slice(0, 16).replace(' ', 'T') : ''} onChange={(e) => setClase({ ...clase, inicio: new Date(e.target.value).toISOString() })} /></Campo>
            <Campo etiqueta="Plataforma"><select value={clase.plataforma} onChange={(e) => setClase({ ...clase, plataforma: e.target.value })}>{Object.entries(PLATAFORMA).map(([v, n]) => <option key={v} value={v}>{n}</option>)}</select></Campo>
            <Campo etiqueta="Enlace de la reunión" ancho><input value={clase.url ?? ''} onChange={(e) => setClase({ ...clase, url: e.target.value })} placeholder="https://" /></Campo>
            <Campo etiqueta="Enlace de la grabación (después de la clase)" ancho><input value={clase.grabacion_url ?? ''} onChange={(e) => setClase({ ...clase, grabacion_url: e.target.value || null })} placeholder="https://" /></Campo>
          </div>
          <p className="ayuda">Alumnos y familias reciben el aviso al programarla y un recordatorio 15 minutos antes.</p>
          <button type="button" className="boton" disabled={accion.enviando || !clase.titulo || !clase.inicio || !clase.url} onClick={() => accion.ejecutar(async () => {
            await pedir(clase.id ? `/aula/cursos/${c.id}/clases/${clase.id}` : `/aula/cursos/${c.id}/clases`, { metodo: clase.id ? 'PUT' : 'POST', cuerpo: clase });
            setClase(null);
            clases.recargar();
          })}>Guardar</button>
        </Dialogo>
      )}
    </>
  );
}

function ForoAbierto({ curso: c, foro, alCerrar }: { curso: Curso; foro: Foro; alCerrar: () => void }) {
  const { pedir } = useSesion();
  const { datos, recargar } = useCarga(() => pedir<{ data: Mensaje[] }>(`/aula/cursos/${c.id}/foros/${foro.id}`), [foro.id]);
  const [texto, setTexto] = useState('');
  const [respondiendo, setRespondiendo] = useState<Mensaje | null>(null);
  const accion = useAccion();
  const puedeEscribir = c.rol === 'docente' || c.rol === 'alumno';
  return (
    <Dialogo titulo={foro.titulo} alCerrar={alCerrar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      {(datos?.data ?? []).map((m) => (
        <div key={m.id} className="tarjeta" style={{ marginLeft: m.respuesta_a ? 24 : 0, opacity: m.oculto ? 0.5 : 1 }}>
          <p style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{m.contenido}</p>
          <small className="ayuda">{m.autor} · {fechaHora(m.created_at)}{m.oculto ? ' · oculto' : ''}</small>{' '}
          {puedeEscribir && <button type="button" className="enlace" onClick={() => setRespondiendo(m)}>Responder</button>}{' '}
          {c.rol === 'docente' && <button type="button" className="enlace" onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/foros/${foro.id}/mensajes/${m.id}`, { metodo: 'PATCH', cuerpo: { oculto: !m.oculto } }); recargar(); })}>{m.oculto ? 'Mostrar' : 'Ocultar'}</button>}
        </div>
      ))}
      {puedeEscribir && (
        <>
          {respondiendo && <p className="ayuda">Respondiendo a {respondiendo.autor} · <button type="button" className="enlace" onClick={() => setRespondiendo(null)}>cancelar</button></p>}
          <Campo etiqueta="Tu mensaje" ancho><textarea rows={3} value={texto} onChange={(e) => setTexto(e.target.value)} maxLength={5000} /></Campo>
          <button type="button" className="boton" disabled={accion.enviando || !texto.trim()} onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/foros/${foro.id}`, { metodo: 'POST', cuerpo: { contenido: texto, respuesta_a: respondiendo?.id ?? null } }); setTexto(''); setRespondiendo(null); recargar(); })}>Publicar</button>
        </>
      )}
    </Dialogo>
  );
}

/** EDUN-133 / 139 / 140 · Seguimiento por alumno, observaciones, encuestas y aviso a la familia. */
export function SeguimientoCurso({ curso: c }: { curso: Curso }) {
  const { pedir } = useSesion();
  const { datos, error, recargar } = useCarga(() => pedir<{ tareas: number; cuestionarios: number; alumnos: FilaSeguimiento[] }>(`/aula/cursos/${c.id}/seguimiento`), [c.id]);
  const encuestas = useCarga(() => (c.rol === 'docente' ? pedir<{ data: { unidad_id: number; unidad: string; respuestas: number; promedio: number | null; comentarios: string[] }[] }>(`/aula/cursos/${c.id}/encuestas`) : Promise.resolve({ data: [] })), [c.id]);
  const [ficha, setFicha] = useState<FilaSeguimiento | null>(null);
  return (
    <>
      <Aviso tipo="error">{error}</Aviso>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Alumno</th><th>Avance</th><th>Tiempo</th><th>Última actividad</th><th>Tareas ({datos.tareas})</th><th>Cuestionarios ({datos.cuestionarios})</th><th /></tr></thead><tbody>
          {datos.alumnos.map((a) => (
            <tr key={a.alumno_id}>
              <td>{a.alumno}{!a.tiene_cuenta && <><br /><small className="ayuda">Sin cuenta en el aula</small></>}</td>
              <td>{a.avance} %{a.video_pct !== null && <><br /><small className="ayuda">Videos {a.video_pct} %</small></>}</td>
              <td>{a.minutos} min</td>
              <td>{a.ultima_actividad ? fechaHora(a.ultima_actividad) : <span className="insignia insignia-ambar">Nunca entró</span>}</td>
              <td>{a.tareas_entregadas}{a.tareas_tardias ? ` (${a.tareas_tardias} tarde)` : ''}</td>
              <td>{a.cuestionarios_rendidos}{a.promedio_cuestionarios !== null ? ` · ${a.promedio_cuestionarios} %` : ''}</td>
              <td><button type="button" className="boton boton-borde boton-chico" onClick={() => setFicha(a)}>Ficha{a.observaciones ? ` (${a.observaciones})` : ''}</button></td>
            </tr>
          ))}
        </tbody></table></div></div>
      )}
      {(encuestas.datos?.data ?? []).some((e) => e.respuestas > 0) && (
        <div className="tarjeta"><h2>Encuestas de las unidades</h2><ul className="lista-simple">{encuestas.datos!.data.filter((e) => e.respuestas).map((e) => (
          <li key={e.unidad_id}>{e.unidad}: {e.promedio} de 4 ({e.respuestas} respuesta(s)){e.comentarios.length > 0 && <><br /><small className="ayuda">«{e.comentarios.join('» · «')}»</small></>}</li>
        ))}</ul></div>
      )}
      {ficha && <FichaAlumno curso={c} fila={ficha} alCerrar={() => { setFicha(null); recargar(); }} />}
    </>
  );
}

const TIPO_OBS: Record<string, string> = { fortaleza: 'Fortaleza', aspecto_mejorar: 'Aspecto a mejorar', comentario: 'Comentario' };

function FichaAlumno({ curso: c, fila, alCerrar }: { curso: Curso; fila: FilaSeguimiento; alCerrar: () => void }) {
  const { pedir } = useSesion();
  const { datos, recargar } = useCarga(() => pedir<{ data: { id: number; tipo: string; texto: string; created_at: string; autor: string }[] }>(`/aula/cursos/${c.id}/observaciones?alumno_id=${fila.alumno_id}`), [fila.alumno_id]);
  const [obs, setObs] = useState({ tipo: 'fortaleza', texto: '' });
  const [mensaje, setMensaje] = useState('');
  const accion = useAccion();
  return (
    <Dialogo titulo={fila.alumno} alCerrar={alCerrar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <p>Avance {fila.avance} % · {fila.minutos} min de estudio · {fila.tareas_entregadas} tarea(s) entregada(s).</p>
      <h3>Observaciones</h3>
      <p className="ayuda">Las ven el docente, tutoría y coordinación; no los alumnos.</p>
      <ul className="lista-simple">{(datos?.data ?? []).map((o) => <li key={o.id}><b>{TIPO_OBS[o.tipo]}:</b> {o.texto}<br /><small className="ayuda">{o.autor} · {fechaHora(o.created_at)}</small></li>)}</ul>
      {c.rol === 'docente' && (
        <div className="filtros">
          <Campo etiqueta="Tipo"><select value={obs.tipo} onChange={(e) => setObs({ ...obs, tipo: e.target.value })}>{Object.entries(TIPO_OBS).map(([v, n]) => <option key={v} value={v}>{n}</option>)}</select></Campo>
          <Campo etiqueta="Observación"><input value={obs.texto} onChange={(e) => setObs({ ...obs, texto: e.target.value })} maxLength={2000} /></Campo>
          <button type="button" className="boton boton-chico" disabled={accion.enviando || obs.texto.trim().length < 3} onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/observaciones`, { metodo: 'POST', cuerpo: { alumno_id: fila.alumno_id, ...obs } }); setObs({ ...obs, texto: '' }); recargar(); })}>Agregar</button>
        </div>
      )}
      <h3>Avisar a la familia</h3>
      <Campo etiqueta="Mensaje" ancho><textarea rows={2} value={mensaje} onChange={(e) => setMensaje(e.target.value)} maxLength={500} placeholder="Ej.: le faltan dos tareas de la unidad 2" /></Campo>
      <button type="button" className="boton" disabled={accion.enviando || mensaje.trim().length < 5} onClick={() => accion.ejecutar(async () => {
        const r = await pedir<{ enviados: number }>(`/aula/cursos/${c.id}/seguimiento/${fila.alumno_id}/aviso`, { metodo: 'POST', cuerpo: { mensaje } });
        setMensaje('');
        accion.setOk(r.enviados ? 'Aviso enviado a la familia.' : 'La familia no tiene la app instalada: el aviso no llegó.');
      })}>Enviar aviso</button>
    </Dialogo>
  );
}
