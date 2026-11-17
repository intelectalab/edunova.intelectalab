import { useEffect, useRef, useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Pestanas, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { CuestionariosDocente, RendirCuestionario } from './Evaluaciones';
import { ForosClases, SeguimientoCurso } from './Comunidad';

export type Recurso = { id: number; tipo: 'archivo' | 'pdf' | 'video' | 'enlace' | 'texto'; titulo: string; url: string | null; contenido: string | null; visible: boolean; archivo_id: number | null; nombre_original: string | null; porcentaje: number; segundos: number; completado: boolean; bloqueado: boolean };
export type Tarea = { id: number; titulo: string; instrucciones: string; vence_at: string; acepta_tardias: boolean; publicada_at: string | null; archivo_id: number | null; competencia_id: number | null; entrega: { entregado_at: string; tardia: boolean; nota: string | null; comentario: string | null } | null };
export type CuestionarioItem = { id: number; titulo: string; publicado_at: string | null; abre_at: string | null; cierra_at: string | null; minutos: number | null; intentos_max: number; bloqueado: boolean };
export type Unidad = { id: number; titulo: string; orden: number; visible_desde: string | null; recursos: Recurso[]; tareas: Tarea[]; cuestionarios: CuestionarioItem[]; encuesta: { respondida: boolean; disponible: boolean } | null };
export type Curso = {
  id: number; titulo: string; area: string; seccion: string; docente: string; escala: 'literal' | 'vigesimal'; rol: 'docente' | 'alumno' | 'familia' | 'seguimiento';
  visible: boolean; secuencial: boolean; video_sin_adelantar: boolean; video_pct_completado: number; encuesta_activa: boolean; avance_visible_familia: boolean;
  unidades: Unidad[]; avisos: { id: number; texto: string; publicado_at: string; autor: string }[]; continuar: { recurso_id: number; titulo: string } | null; avance: number | null;
  competencias?: { id: number; nombre: string }[];
};
type CursoLista = { id: number; titulo: string; area: string; seccion: string; docente: string; pendientes: number; visible: boolean };

const TIPO_RECURSO: Record<Recurso['tipo'], string> = { archivo: 'Archivo', pdf: 'PDF', video: 'Video', enlace: 'Enlace', texto: 'Lectura' };

/** EDUN-123 · Aula virtual: mis cursos (docente, alumno o coordinación) y el curso abierto. */
export function AulaVirtual() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error } = useCarga(() => pedir<{ data: CursoLista[] }>('/aula/cursos'));
  const [abierto, setAbierto] = useState<number | null>(null);
  if (abierto) return <CursoAbierto id={abierto} alVolver={() => setAbierto(null)} />;
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Académico`} titulo="Aula virtual" descripcion="Cada curso se crea solo desde la carga docente y los alumnos entran por su matrícula." />
      <Aviso tipo="error">{error}</Aviso>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No tienes cursos en el aula. Los cursos aparecen cuando la carga docente del año está registrada.</p> : (
        <div className="rejilla">
          {datos.data.map((c) => (
            <button key={c.id} type="button" className="tarjeta" style={{ textAlign: 'left', cursor: 'pointer' }} onClick={() => setAbierto(c.id)}>
              <h2 style={{ margin: 0 }}>{c.area}</h2>
              <p className="ayuda">{c.seccion} · {c.docente || 'Sin docente'}</p>
              {c.pendientes > 0 && <span className="insignia insignia-ambar">{c.pendientes} pendiente(s)</span>}
              {!c.visible && <span className="insignia insignia-gris">Oculto para los alumnos</span>}
            </button>
          ))}
        </div>
      )}
    </>
  );
}

function CursoAbierto({ id, alVolver }: { id: number; alVolver: () => void }) {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos: c, error, recargar } = useCarga(() => pedir<Curso>(`/aula/cursos/${id}`), [id]);
  const [pestana, setPestana] = useState<'contenido' | 'evaluaciones' | 'foros' | 'seguimiento' | 'configuracion'>('contenido');
  if (!c) return <><Aviso tipo="error">{error}</Aviso><p role="status">Cargando…</p></>;
  const docente = c.rol === 'docente';
  const opciones: [typeof pestana, string][] = [['contenido', 'Contenido']];
  if (docente) opciones.push(['evaluaciones', 'Cuestionarios y banco']);
  if (c.rol !== 'familia') opciones.push(['foros', 'Foros y clases en vivo']);
  if (docente || c.rol === 'seguimiento') opciones.push(['seguimiento', 'Seguimiento']);
  if (docente) opciones.push(['configuracion', 'Configuración']);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Aula virtual`} titulo={c.titulo} descripcion={`${c.seccion} · ${c.docente}`}>
        <button type="button" className="boton boton-borde" onClick={alVolver}>Mis cursos</button>
      </Titulo>
      <Aviso tipo="error">{error}</Aviso>
      {c.avance !== null && <p>Tu avance: <b>{c.avance} %</b>{c.continuar && <> · Continúa con «{c.continuar.titulo}»</>}</p>}
      <Pestanas opciones={opciones} actual={pestana} alCambiar={setPestana} />
      {pestana === 'contenido' && <Contenido curso={c} recargar={recargar} />}
      {pestana === 'evaluaciones' && <CuestionariosDocente curso={c} recargar={recargar} />}
      {pestana === 'foros' && <ForosClases curso={c} />}
      {pestana === 'seguimiento' && <SeguimientoCurso curso={c} />}
      {pestana === 'configuracion' && <Configuracion curso={c} recargar={recargar} />}
    </>
  );
}

function Contenido({ curso: c, recargar }: { curso: Curso; recargar: () => void }) {
  const { pedir } = useSesion();
  const docente = c.rol === 'docente';
  const accion = useAccion();
  const [editUnidad, setEditUnidad] = useState<{ id?: number; titulo: string; visible_desde: string } | null>(null);
  const [nuevoRecurso, setNuevoRecurso] = useState<number | null>(null);
  const [tarea, setTarea] = useState<{ unidad: number; t?: Tarea } | null>(null);
  const [entregas, setEntregas] = useState<Tarea | null>(null);
  const [viendo, setViendo] = useState<Recurso | null>(null);
  const [entregar, setEntregar] = useState<Tarea | null>(null);
  const [rendir, setRendir] = useState<CuestionarioItem | null>(null);
  const [aviso, setAviso] = useState('');
  const abrirArchivo = (r: Recurso) => accion.ejecutar(async () => {
    window.open((await pedir<{ url: string }>(`/aula/cursos/${c.id}/recursos/${r.id}/archivo`)).url, '_blank', 'noopener');
    if (c.rol === 'alumno' && r.tipo !== 'video') { await pedir(`/aula/cursos/${c.id}/recursos/${r.id}/avance`, { metodo: 'POST', cuerpo: { porcentaje: 100 } }); recargar(); }
  });
  const abrirRecurso = (r: Recurso) => {
    if (r.bloqueado) return accion.setError('Completa primero el contenido anterior.');
    if (r.tipo === 'texto' || r.tipo === 'video') return setViendo(r);
    if (r.tipo === 'enlace') {
      window.open(r.url!, '_blank', 'noopener');
      if (c.rol === 'alumno') void pedir(`/aula/cursos/${c.id}/recursos/${r.id}/avance`, { metodo: 'POST', cuerpo: { porcentaje: 100 } }).then(recargar);
      return;
    }
    void abrirArchivo(r);
  };
  return (
    <>
      <Aviso tipo="error">{accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {docente && (
        <div className="tarjeta">
          <Campo etiqueta="Aviso para el curso (llega a alumnos y familias)" ancho><textarea rows={2} value={aviso} onChange={(e) => setAviso(e.target.value)} maxLength={2000} /></Campo>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando || aviso.trim().length < 3} onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/avisos`, { metodo: 'POST', cuerpo: { texto: aviso } }); setAviso(''); recargar(); })}>Publicar aviso</button>
            <button type="button" className="boton boton-borde" onClick={() => setEditUnidad({ titulo: '', visible_desde: '' })}>Nueva unidad</button>
          </div>
        </div>
      )}
      {c.avisos.length > 0 && (
        <div className="tarjeta"><h2>Avisos</h2><ul className="lista-simple">{c.avisos.map((a) => <li key={a.id}>{a.texto}<br /><small className="ayuda">{a.autor} · {fechaHora(a.publicado_at)}</small>{docente && <> <button type="button" className="enlace" onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/avisos/${a.id}`, { metodo: 'DELETE' }); recargar(); })}>Quitar</button></>}</li>)}</ul></div>
      )}
      {c.unidades.length === 0 && <p className="vacio">{docente ? 'Crea la primera unidad para agregar materiales, tareas y cuestionarios.' : 'El docente todavía no publicó contenido.'}</p>}
      {c.unidades.map((u) => (
        <div key={u.id} className="tarjeta">
          <div className="titulo-fila"><h2>{u.titulo}</h2>
            {docente && <div className="acciones">
              <button type="button" className="boton boton-borde boton-chico" onClick={() => setEditUnidad({ id: u.id, titulo: u.titulo, visible_desde: u.visible_desde ?? '' })}>Editar</button>
              <button type="button" className="boton boton-borde boton-chico" onClick={() => setNuevoRecurso(u.id)}>Material</button>
              <button type="button" className="boton boton-borde boton-chico" onClick={() => setTarea({ unidad: u.id })}>Tarea</button>
            </div>}
          </div>
          {u.visible_desde && docente && <p className="ayuda">Visible desde {u.visible_desde}</p>}
          <ul className="lista-simple">
            {u.recursos.map((r) => (
              <li key={r.id}>
                <button type="button" className="enlace" disabled={r.bloqueado} onClick={() => abrirRecurso(r)}>{r.bloqueado ? '🔒 ' : r.completado ? '✓ ' : ''}{r.titulo}</button>
                {' '}<small className="ayuda">{TIPO_RECURSO[r.tipo]}{r.tipo === 'video' && c.rol === 'alumno' ? ` · ${r.porcentaje} %` : ''}{!r.visible ? ' · oculto' : ''}</small>
                {docente && <> <button type="button" className="enlace" onClick={() => window.confirm(`¿Quitar «${r.titulo}»?`) && accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/recursos/${r.id}`, { metodo: 'DELETE' }); recargar(); })}>Quitar</button></>}
              </li>
            ))}
            {u.tareas.map((t) => (
              <li key={`t${t.id}`}>
                <b>Tarea:</b> {t.titulo} · vence {fechaHora(t.vence_at)}{!t.publicada_at && <span className="insignia insignia-gris">Borrador</span>}
                {c.rol === 'alumno' && (t.entrega ? <> · <span className={`insignia ${t.entrega.nota ? 'insignia-ok' : 'insignia-azul'}`}>{t.entrega.nota ? `Nota ${t.entrega.nota}` : t.entrega.tardia ? 'Entregada tarde' : 'Entregada'}</span>{t.entrega.comentario && <small className="ayuda"> «{t.entrega.comentario}»</small>}</> : null)}
                {' '}{c.rol === 'alumno' && !t.entrega?.nota && <button type="button" className="boton boton-chico" onClick={() => setEntregar(t)}>{t.entrega ? 'Volver a entregar' : 'Entregar'}</button>}
                {docente && <>
                  <button type="button" className="boton boton-borde boton-chico" onClick={() => setEntregas(t)}>Entregas</button>
                  <button type="button" className="boton boton-borde boton-chico" onClick={() => setTarea({ unidad: u.id, t })}>Editar</button>
                  {!t.publicada_at && <button type="button" className="boton boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/tareas/${t.id}/publicacion`, { metodo: 'POST' }); recargar(); })}>Publicar</button>}
                </>}
              </li>
            ))}
            {u.cuestionarios.map((q) => (
              <li key={`q${q.id}`}>
                <b>Cuestionario:</b> {q.titulo}{q.minutos ? ` · ${q.minutos} min` : ''}{!q.publicado_at && <span className="insignia insignia-gris">Borrador</span>}
                {c.rol === 'alumno' && <> <button type="button" className="boton boton-chico" disabled={q.bloqueado} title={q.bloqueado ? 'Completa los materiales de la unidad' : undefined} onClick={() => setRendir(q)}>{q.bloqueado ? '🔒 Rendir' : 'Rendir'}</button></>}
              </li>
            ))}
          </ul>
          {u.encuesta && !u.encuesta.respondida && u.encuesta.disponible && <Encuesta cursoId={c.id} unidadId={u.id} alResponder={recargar} />}
        </div>
      ))}
      {editUnidad && (
        <Dialogo titulo={editUnidad.id ? 'Editar unidad' : 'Nueva unidad'} alCerrar={() => setEditUnidad(null)}>
          <div className="campos">
            <Campo etiqueta="Título"><input value={editUnidad.titulo} onChange={(e) => setEditUnidad({ ...editUnidad, titulo: e.target.value })} maxLength={150} /></Campo>
            <Campo etiqueta="Visible desde (opcional)"><input type="date" value={editUnidad.visible_desde} onChange={(e) => setEditUnidad({ ...editUnidad, visible_desde: e.target.value })} /></Campo>
          </div>
          <div className="acciones">
            <button type="button" className="boton" disabled={editUnidad.titulo.trim().length < 2} onClick={() => accion.ejecutar(async () => {
              await pedir(editUnidad.id ? `/aula/cursos/${c.id}/unidades/${editUnidad.id}` : `/aula/cursos/${c.id}/unidades`, { metodo: editUnidad.id ? 'PUT' : 'POST', cuerpo: { titulo: editUnidad.titulo, visible_desde: editUnidad.visible_desde || null } });
              setEditUnidad(null);
              recargar();
            })}>Guardar</button>
            {editUnidad.id && <button type="button" className="boton boton-peligro" onClick={() => window.confirm('¿Borrar la unidad con sus materiales, tareas y cuestionarios?') && accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/unidades/${editUnidad.id}`, { metodo: 'DELETE' }); setEditUnidad(null); recargar(); })}>Borrar</button>}
          </div>
        </Dialogo>
      )}
      {nuevoRecurso && <NuevoRecurso cursoId={c.id} unidadId={nuevoRecurso} alTerminar={() => { setNuevoRecurso(null); recargar(); }} />}
      {tarea && <EditarTarea curso={c} unidadId={tarea.unidad} tarea={tarea.t} alTerminar={() => { setTarea(null); recargar(); }} />}
      {entregas && <Entregas curso={c} tarea={entregas} alCerrar={() => setEntregas(null)} />}
      {entregar && <EntregarTarea cursoId={c.id} tarea={entregar} alTerminar={() => { setEntregar(null); recargar(); }} />}
      {viendo && <VerRecurso curso={c} recurso={viendo} alCerrar={() => { setViendo(null); recargar(); }} />}
      {rendir && <RendirCuestionario cursoId={c.id} cuestionario={rendir} alCerrar={() => { setRendir(null); recargar(); }} />}
    </>
  );
}

/** EDUN-136 · Lectura en línea o video con porcentaje mínimo (sin adelantar si el curso lo indica). */
function VerRecurso({ curso: c, recurso: r, alCerrar }: { curso: Curso; recurso: Recurso; alCerrar: () => void }) {
  const { pedir } = useSesion();
  const [url, setUrl] = useState<string | null>(r.tipo === 'video' && r.url ? r.url : null);
  const [error, setError] = useState<string | null>(null);
  const visto = useRef(r.porcentaje);
  const ultimoEnvio = useRef(0);
  const alumno = c.rol === 'alumno';
  useEffect(() => {
    if (r.tipo === 'texto' && alumno && !r.completado) void pedir(`/aula/cursos/${c.id}/recursos/${r.id}/avance`, { metodo: 'POST', cuerpo: { porcentaje: 100 } });
    if (r.tipo === 'video' && r.archivo_id) {
      pedir<{ url: string }>(`/aula/cursos/${c.id}/recursos/${r.id}/archivo`).then((x) => setUrl(x.url)).catch(() => setError('No se pudo abrir el video.'));
    }
  }, [c.id, r.id, r.tipo, r.archivo_id, r.completado, alumno, pedir]);
  const enviar = (v: HTMLVideoElement) => {
    if (!alumno || !v.duration) return;
    const pct = Math.floor((v.currentTime / v.duration) * 100);
    if (pct > visto.current) visto.current = pct;
    if (Date.now() - ultimoEnvio.current < 5000 && pct < 100) return;
    ultimoEnvio.current = Date.now();
    void pedir(`/aula/cursos/${c.id}/recursos/${r.id}/avance`, { metodo: 'POST', cuerpo: { porcentaje: visto.current, segundos: Math.floor(v.currentTime) } }).catch(() => undefined);
  };
  return (
    <Dialogo titulo={r.titulo} alCerrar={alCerrar}>
      {error && <Aviso tipo="error">{error}</Aviso>}
      {r.tipo === 'texto' && <div style={{ whiteSpace: 'pre-wrap' }}>{r.contenido}</div>}
      {r.tipo === 'video' && url && !r.archivo_id && <p>Este video está en otro sitio: <a href={url} target="_blank" rel="noopener noreferrer">ábrelo aquí</a>.</p>}
      {r.tipo === 'video' && url && r.archivo_id && (
        <>
          {/* eslint-disable-next-line jsx-a11y/media-has-caption -- videos de clase subidos por el docente */}
          <video src={url} controls controlsList="nodownload" style={{ width: '100%', maxHeight: 420 }}
            onLoadedMetadata={(e) => { if (r.segundos) e.currentTarget.currentTime = r.segundos; }}
            onTimeUpdate={(e) => enviar(e.currentTarget)}
            onSeeking={(e) => {
              const v = e.currentTarget;
              // Sin adelantar: no se puede saltar más allá de lo ya visto.
              if (alumno && c.video_sin_adelantar && v.duration && v.currentTime > (visto.current / 100) * v.duration + 2) v.currentTime = (visto.current / 100) * v.duration;
            }}
            onEnded={(e) => enviar(e.currentTarget)} />
          {alumno && <p className="ayuda">Se marca como visto al llegar al {c.video_pct_completado} %.{c.video_sin_adelantar ? ' No se puede adelantar.' : ''}</p>}
        </>
      )}
      <div className="acciones"><button type="button" className="boton boton-borde" onClick={alCerrar}>Cerrar</button></div>
    </Dialogo>
  );
}

function NuevoRecurso({ cursoId, unidadId, alTerminar }: { cursoId: number; unidadId: number; alTerminar: () => void }) {
  const { pedir } = useSesion();
  const [f, setF] = useState({ tipo: 'pdf' as Recurso['tipo'], titulo: '', url: '', contenido: '' });
  const [archivo, setArchivo] = useState<File | null>(null);
  const accion = useAccion();
  return (
    <Dialogo titulo="Nuevo material" alCerrar={alTerminar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="campos">
        <Campo etiqueta="Tipo"><select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value as Recurso['tipo'] })}>{Object.entries(TIPO_RECURSO).map(([v, n]) => <option key={v} value={v}>{n}</option>)}</select></Campo>
        <Campo etiqueta="Título"><input value={f.titulo} onChange={(e) => setF({ ...f, titulo: e.target.value })} maxLength={150} /></Campo>
        {(f.tipo === 'enlace' || f.tipo === 'video') && <Campo etiqueta={f.tipo === 'video' ? 'Enlace del video (o sube el archivo)' : 'Enlace'} ayuda={f.tipo === 'video' ? 'Solo los videos subidos controlan el porcentaje visto.' : undefined}><input value={f.url} onChange={(e) => setF({ ...f, url: e.target.value })} placeholder="https://" /></Campo>}
        {['archivo', 'pdf', 'video'].includes(f.tipo) && <Campo etiqueta="Archivo" ayuda="Hasta 100 MB."><input type="file" accept={f.tipo === 'pdf' ? 'application/pdf' : f.tipo === 'video' ? 'video/mp4,video/webm' : undefined} onChange={(e) => setArchivo(e.target.files?.[0] ?? null)} /></Campo>}
        {f.tipo === 'texto' && <Campo etiqueta="Contenido" ancho><textarea rows={8} value={f.contenido} onChange={(e) => setF({ ...f, contenido: e.target.value })} /></Campo>}
      </div>
      <div className="acciones"><button type="button" className="boton" disabled={accion.enviando || f.titulo.trim().length < 2} onClick={() => accion.ejecutar(async () => {
        const cuerpo = new FormData();
        cuerpo.append('tipo', f.tipo);
        cuerpo.append('titulo', f.titulo);
        if (f.url) cuerpo.append('url', f.url);
        if (f.contenido) cuerpo.append('contenido', f.contenido);
        if (archivo) cuerpo.append('archivo', archivo);
        await pedir(`/aula/cursos/${cursoId}/unidades/${unidadId}/recursos`, { metodo: 'POST', cuerpo });
        alTerminar();
      })}>{accion.enviando ? 'Subiendo…' : 'Agregar'}</button></div>
    </Dialogo>
  );
}

const enLocal = (iso?: string) => (iso ? new Date(iso).toLocaleString('sv-SE').slice(0, 16).replace(' ', 'T') : '');

function EditarTarea({ curso: c, unidadId, tarea, alTerminar }: { curso: Curso; unidadId: number; tarea?: Tarea; alTerminar: () => void }) {
  const { pedir } = useSesion();
  const [f, setF] = useState(() => ({ titulo: tarea?.titulo ?? '', instrucciones: tarea?.instrucciones ?? '', vence_at: enLocal(tarea?.vence_at), acepta_tardias: tarea?.acepta_tardias ?? true, competencia_id: tarea?.competencia_id ? String(tarea.competencia_id) : '' }));
  const [archivo, setArchivo] = useState<File | null>(null);
  const accion = useAccion();
  const guardar = (publicar: boolean) => accion.ejecutar(async () => {
    const cuerpo = new FormData();
    cuerpo.append('aula_unidad_id', String(unidadId));
    cuerpo.append('titulo', f.titulo);
    cuerpo.append('instrucciones', f.instrucciones);
    cuerpo.append('vence_at', new Date(f.vence_at).toISOString());
    cuerpo.append('acepta_tardias', f.acepta_tardias ? '1' : '0');
    if (f.competencia_id) cuerpo.append('competencia_id', f.competencia_id);
    cuerpo.append('publicar', publicar ? '1' : '0');
    if (archivo) cuerpo.append('archivo', archivo);
    await pedir(tarea ? `/aula/cursos/${c.id}/tareas/${tarea.id}` : `/aula/cursos/${c.id}/tareas`, { metodo: 'POST', cuerpo });
    alTerminar();
  });
  return (
    <Dialogo titulo={tarea ? 'Editar tarea' : 'Nueva tarea'} alCerrar={alTerminar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="campos">
        <Campo etiqueta="Título"><input value={f.titulo} onChange={(e) => setF({ ...f, titulo: e.target.value })} maxLength={150} /></Campo>
        <Campo etiqueta="Entrega hasta"><input type="datetime-local" value={f.vence_at} onChange={(e) => setF({ ...f, vence_at: e.target.value })} /></Campo>
        <Campo etiqueta="Competencia que evalúa (opcional)"><select value={f.competencia_id} onChange={(e) => setF({ ...f, competencia_id: e.target.value })}><option value="">—</option>{(c.competencias ?? []).map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}</select></Campo>
        <label className="campo"><span><input type="checkbox" checked={f.acepta_tardias} onChange={(e) => setF({ ...f, acepta_tardias: e.target.checked })} /> Acepta entregas tardías</span></label>
        <Campo etiqueta="Instrucciones" ancho><textarea rows={6} value={f.instrucciones} onChange={(e) => setF({ ...f, instrucciones: e.target.value })} /></Campo>
        <Campo etiqueta="Archivo de apoyo (opcional)"><input type="file" onChange={(e) => setArchivo(e.target.files?.[0] ?? null)} /></Campo>
      </div>
      <div className="acciones">
        <button type="button" className="boton" disabled={accion.enviando || !f.vence_at || f.titulo.trim().length < 3} onClick={() => guardar(true)}>{tarea?.publicada_at ? 'Guardar' : 'Guardar y publicar'}</button>
        {!tarea?.publicada_at && <button type="button" className="boton boton-borde" disabled={accion.enviando || !f.vence_at} onClick={() => guardar(false)}>Guardar borrador</button>}
      </div>
    </Dialogo>
  );
}

type FilaEntrega = { alumno_id: number; alumno: string; estado: 'pendiente' | 'entregada' | 'revisada'; entregado_at: string | null; tardia: boolean; texto: string | null; archivo: string | null; nota: string | null; comentario: string | null };

function Entregas({ curso: c, tarea, alCerrar }: { curso: Curso; tarea: Tarea; alCerrar: () => void }) {
  const { pedir } = useSesion();
  const { datos, recargar } = useCarga(() => pedir<{ alumnos: FilaEntrega[] }>(`/aula/cursos/${c.id}/tareas/${tarea.id}/entregas`), [tarea.id]);
  const [nota, setNota] = useState<Record<number, { nota: string; comentario: string }>>({});
  const accion = useAccion();
  const opciones = c.escala === 'vigesimal' ? Array.from({ length: 21 }, (_, i) => String(i).padStart(2, '0')) : ['AD', 'A', 'B', 'C'];
  return (
    <Dialogo titulo={`Entregas: ${tarea.titulo}`} alCerrar={alCerrar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      {!datos ? <p role="status">Cargando…</p> : (
        <div className="tabla-caja"><table className="tabla"><thead><tr><th>Alumno</th><th>Entrega</th><th>Nota</th></tr></thead><tbody>
          {datos.alumnos.map((a) => {
            const n = nota[a.alumno_id] ?? { nota: a.nota ?? '', comentario: a.comentario ?? '' };
            return (
              <tr key={a.alumno_id}>
                <td>{a.alumno}</td>
                <td>{a.estado === 'pendiente' ? <span className="insignia insignia-ambar">Sin entregar</span> : <>
                  {fechaHora(a.entregado_at)}{a.tardia && <span className="insignia insignia-rojo">Tardía</span>}
                  {a.texto && <p style={{ whiteSpace: 'pre-wrap' }}>{a.texto}</p>}
                  {a.archivo && <button type="button" className="enlace" onClick={() => accion.ejecutar(async () => window.open((await pedir<{ url: string }>(`/aula/cursos/${c.id}/tareas/${tarea.id}/entregas/${a.alumno_id}/archivo`)).url, '_blank', 'noopener'))}>{a.archivo}</button>}
                </>}</td>
                <td>{a.estado !== 'pendiente' && <div className="filtros">
                  <select aria-label={`Nota de ${a.alumno}`} value={n.nota} onChange={(e) => setNota({ ...nota, [a.alumno_id]: { ...n, nota: e.target.value } })}><option value="">—</option>{opciones.map((o) => <option key={o}>{o}</option>)}</select>
                  <input aria-label={`Comentario para ${a.alumno}`} value={n.comentario} placeholder="Comentario" onChange={(e) => setNota({ ...nota, [a.alumno_id]: { ...n, comentario: e.target.value } })} />
                  <button type="button" className="boton boton-chico" disabled={!n.nota || accion.enviando} onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/tareas/${tarea.id}/entregas/${a.alumno_id}/revision`, { metodo: 'POST', cuerpo: { nota: n.nota, comentario: n.comentario || null } }); recargar(); })}>{a.estado === 'revisada' ? 'Actualizar' : 'Calificar'}</button>
                </div>}</td>
              </tr>
            );
          })}
        </tbody></table></div>
      )}
      <p className="ayuda">La nota es una sugerencia: pásala al registro oficial en Notas.</p>
    </Dialogo>
  );
}

function EntregarTarea({ cursoId, tarea, alTerminar }: { cursoId: number; tarea: Tarea; alTerminar: () => void }) {
  const { pedir } = useSesion();
  const [texto, setTexto] = useState('');
  const [archivo, setArchivo] = useState<File | null>(null);
  const accion = useAccion();
  return (
    <Dialogo titulo={`Entregar: ${tarea.titulo}`} alCerrar={alTerminar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      <p style={{ whiteSpace: 'pre-wrap' }}>{tarea.instrucciones}</p>
      {tarea.archivo_id && <button type="button" className="enlace" onClick={() => accion.ejecutar(async () => window.open((await pedir<{ url: string }>(`/aula/cursos/${cursoId}/tareas/${tarea.id}/archivo`)).url, '_blank', 'noopener'))}>Archivo de la tarea</button>}
      <p className="ayuda">Entrega hasta {fechaHora(tarea.vence_at)}.{tarea.acepta_tardias ? ' Después se acepta como tardía.' : ' Después no se acepta.'}</p>
      <Campo etiqueta="Tu respuesta" ancho><textarea rows={5} value={texto} onChange={(e) => setTexto(e.target.value)} /></Campo>
      <Campo etiqueta="Archivo (opcional)" ayuda="Hasta 20 MB."><input type="file" onChange={(e) => setArchivo(e.target.files?.[0] ?? null)} /></Campo>
      <div className="acciones"><button type="button" className="boton" disabled={accion.enviando || (!texto.trim() && !archivo)} onClick={() => accion.ejecutar(async () => {
        const cuerpo = new FormData();
        if (texto.trim()) cuerpo.append('texto', texto.trim());
        if (archivo) cuerpo.append('archivo', archivo);
        await pedir(`/aula/cursos/${cursoId}/tareas/${tarea.id}/entrega`, { metodo: 'POST', cuerpo });
        alTerminar();
      })}>Entregar</button></div>
    </Dialogo>
  );
}

const CARITAS = ['😞', '😐', '🙂', '😄'];

/** EDUN-139 · Encuesta de la unidad (anónima para los compañeros). */
function Encuesta({ cursoId, unidadId, alResponder }: { cursoId: number; unidadId: number; alResponder: () => void }) {
  const { pedir } = useSesion();
  const [valor, setValor] = useState(0);
  const [comentario, setComentario] = useState('');
  const accion = useAccion();
  return (
    <div className="tarjeta">
      <p>¿Qué te pareció esta unidad?</p>
      <div className="acciones">{CARITAS.map((c, i) => <button key={c} type="button" aria-label={`${i + 1} de 4`} aria-pressed={valor === i + 1} className={`boton ${valor === i + 1 ? '' : 'boton-borde'}`} onClick={() => setValor(i + 1)}>{c}</button>)}</div>
      <Campo etiqueta="Comentario (opcional)"><input value={comentario} onChange={(e) => setComentario(e.target.value)} maxLength={300} /></Campo>
      <Aviso tipo="error">{accion.error}</Aviso>
      <button type="button" className="boton boton-chico" disabled={!valor || accion.enviando} onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${cursoId}/unidades/${unidadId}/encuesta`, { metodo: 'POST', cuerpo: { valor, comentario: comentario || null } }); alResponder(); })}>Enviar</button>
    </div>
  );
}

function Configuracion({ curso: c, recargar }: { curso: Curso; recargar: () => void }) {
  const { pedir } = useSesion();
  const [f, setF] = useState({ visible: c.visible, secuencial: c.secuencial, video_sin_adelantar: c.video_sin_adelantar, video_pct_completado: c.video_pct_completado, encuesta_activa: c.encuesta_activa, avance_visible_familia: c.avance_visible_familia });
  const accion = useAccion();
  const casilla = (k: keyof typeof f, texto: string) => <label className="campo"><span><input type="checkbox" checked={Boolean(f[k])} onChange={(e) => setF({ ...f, [k]: e.target.checked })} /> {texto}</span></label>;
  return (
    <div className="tarjeta">
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="campos">
        {casilla('visible', 'Curso visible para los alumnos')}
        {casilla('secuencial', 'Desbloqueo en orden (un contenido se habilita al completar el anterior)')}
        {casilla('video_sin_adelantar', 'Los videos no se pueden adelantar')}
        <Campo etiqueta="Porcentaje para completar un video"><input type="number" min={10} max={100} value={f.video_pct_completado} onChange={(e) => setF({ ...f, video_pct_completado: Number(e.target.value) })} /></Campo>
        {casilla('encuesta_activa', 'Encuesta de satisfacción al terminar cada unidad')}
        {casilla('avance_visible_familia', 'La familia ve el avance')}
      </div>
      <button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}`, { metodo: 'PATCH', cuerpo: f }); recargar(); accion.setOk('Configuración guardada.'); })}>Guardar</button>
    </div>
  );
}

type TareaHijo = { id: number; titulo: string; curso: string; vence_at: string; alumno: string; estado: 'pendiente' | 'entregada' | 'revisada' | 'vencida'; tardia: boolean; nota: string | null };
const ESTADO_HIJO = { pendiente: 'insignia-ambar', entregada: 'insignia-azul', revisada: 'insignia-ok', vencida: 'insignia-rojo' };

/** EDUN-134 · La familia ve las tareas de sus hijos. */
export function TareasHijos() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error } = useCarga(() => pedir<{ data: TareaHijo[] }>('/aula/familia/tareas'));
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Tareas del aula" descripcion="Tareas publicadas por los docentes, con la fecha límite y si tu hijo ya entregó." />
      <Aviso tipo="error">{error}</Aviso>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hay tareas recientes.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Hijo</th><th>Curso</th><th>Tarea</th><th>Vence</th><th>Estado</th></tr></thead><tbody>
          {datos.data.map((t) => <tr key={`${t.id}-${t.alumno}`}><td>{t.alumno}</td><td>{t.curso}</td><td>{t.titulo}</td><td>{fechaHora(t.vence_at)}</td><td><span className={`insignia ${ESTADO_HIJO[t.estado]}`}>{t.estado === 'revisada' ? `Nota ${t.nota}` : t.estado === 'entregada' ? (t.tardia ? 'Entregada tarde' : 'Entregada') : t.estado === 'vencida' ? 'No entregó' : 'Pendiente'}</span></td></tr>)}
        </tbody></table></div></div>
      )}
    </>
  );
}
