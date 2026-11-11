import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from '../adm/anios';
import { SelectorAnio } from '../adm/SelectorAnio';

type FilaAsis = { id: number; nombre: string; estado: string; hora: string | null; origen: string | null };
type AlumnoBusqueda = { id: number; apellido_paterno: string; apellido_materno: string | null; nombres: string; seccion: string | null };
type Historial = {
  conducta: { id: number; fecha: string; tipo: string; gravedad: string | null; detalle: string; notificado_padre_at: string | null; registrado_por: string | null }[];
  entrevistas: { id: number; fecha: string; participantes: string; acuerdos: string; confidencial: boolean; registrado_por: string | null }[];
  alertas: { id: number; motivo: string; detalle: string; estado: string }[];
};
type Alerta = { id: number; alumno_id: number; alumno: string; seccion: string | null; motivo: string; nombre_motivo: string; detalle: string; nivel: string; estado: string; accion: string | null; created_at: string };

const COLOR: Record<string, string> = { P: 'insignia-ok', T: 'insignia-ambar', F: 'insignia-rojo', J: '' };

/** EDUN-94 / EDUN-95 · Asistencia del día (también por código o QR del carné). */
export function AsistenciaDiaria() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { anios, anio, setAnio } = useAnioEscolar();
  const secciones = useCarga(() => (anio ? pedir<{ data: { id: number; nombre: string }[] }>(`/asistencia/secciones?anio_escolar_id=${anio}`) : Promise.resolve({ data: [] })), [anio]);
  const [eleccion, setSeccion] = useState<number | null>(null);
  const seccion = eleccion ?? secciones.datos?.data[0]?.id ?? null;
  const [dia, setDia] = useState(() => new Date().toISOString().slice(0, 10));
  const { datos, setDatos, error } = useCarga(() => (seccion ? pedir<{ tomada: boolean; alumnos: FilaAsis[] }>(`/asistencia/secciones/${seccion}?fecha=${dia}`) : Promise.resolve(null)), [seccion, dia]);
  const accion = useAccion();
  const [codigo, setCodigo] = useState('');
  const marcar = (id: number, e: string) => datos && setDatos({ ...datos, alumnos: datos.alumnos.map((a) => (a.id === id ? { ...a, estado: e } : a)) });

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Académico`} titulo="Asistencia" descripcion="Todos presentes por defecto: marca solo las tardanzas y faltas. La familia recibe el aviso el mismo día." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="filtros">
        {anios.length > 0 && <SelectorAnio anios={anios} anio={anio} alCambiar={(id) => { setAnio(id); setSeccion(null); }} />}
        <Campo etiqueta="Sección">
          <select value={seccion ?? ''} onChange={(e) => setSeccion(Number(e.target.value))}>{secciones.datos?.data.map((s) => <option key={s.id} value={s.id}>{s.nombre}</option>)}</select>
        </Campo>
        <Campo etiqueta="Fecha"><input type="date" value={dia} onChange={(e) => setDia(e.target.value)} /></Campo>
        {puede(colegio, 'cal.asistencia.registrar') && (
          <Campo etiqueta="Código del carné (lector QR)">
            <input value={codigo} placeholder="Escanea o escribe" onChange={(e) => setCodigo(e.target.value)} onKeyDown={(e) => {
              if (e.key === 'Enter' && codigo.trim()) {
                const c = codigo.trim();
                setCodigo('');
                accion.ejecutar(async () => {
                  const r = await pedir<{ alumno: string; estado: string; hora: string; repetido: boolean }>('/asistencia/qr', { metodo: 'POST', cuerpo: { codigo: c } });
                  accion.setOk(`${r.alumno}: ${r.estado === 'T' ? 'tardanza' : 'puntual'} a las ${r.hora}${r.repetido ? ' (ya había llegado)' : ''}.`);
                });
              }
            }} />
          </Campo>
        )}
      </div>
      {datos && (
        <div className="tarjeta">
          <p className="ayuda">{datos.tomada ? 'Asistencia ya registrada: puedes corregirla.' : 'Aún no se registra la asistencia de este día.'}</p>
          <div className="tabla-caja">
            <table className="tabla">
              <thead><tr><th>Alumno</th><th>Estado</th><th>Hora</th></tr></thead>
              <tbody>
                {datos.alumnos.map((a) => (
                  <tr key={a.id}>
                    <td>{a.nombre}</td>
                    <td>
                      <div style={{ display: 'flex', gap: 4 }}>
                        {['P', 'T', 'F', 'J'].map((e) => (
                          <button key={e} type="button" aria-pressed={a.estado === e} className={`boton boton-chico ${a.estado === e ? '' : 'boton-borde'}`} onClick={() => marcar(a.id, e)}>{e}</button>
                        ))}
                      </div>
                    </td>
                    <td>{a.hora ? a.hora.slice(0, 5) : '—'} {a.origen === 'qr' && <span className={`insignia ${COLOR[a.estado]}`}>QR</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {datos.alumnos.length === 0 && <p className="vacio">La sección no tiene alumnos matriculados.</p>}
          </div>
          {puede(colegio, 'cal.asistencia.registrar') && (
            <div className="acciones">
              <button type="button" className="boton" disabled={accion.enviando || !datos.alumnos.length} onClick={() => accion.ejecutar(async () => {
                const r = await pedir<{ guardados: number }>(`/asistencia/secciones/${seccion}`, { metodo: 'PUT', cuerpo: { fecha: dia, origen: 'web', registros: datos.alumnos.map((a) => ({ alumno_id: a.id, estado: a.estado })) } });
                setDatos({ ...datos, tomada: true });
                accion.setOk(`Asistencia guardada (${r.guardados} alumnos).`);
              })}>Guardar asistencia</button>
            </div>
          )}
        </div>
      )}
    </>
  );
}

/** EDUN-101 / EDUN-102 · Conducta, méritos y entrevistas de tutoría por alumno. */
export function TutoriaAlumno() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [q, setQ] = useState('');
  const busqueda = useCarga(() => (q.trim().length >= 3 ? pedir<{ data: AlumnoBusqueda[] }>(`/alumnos?buscar=${encodeURIComponent(q.trim())}`) : Promise.resolve({ data: [] })), [q]);
  const [alumno, setAlumno] = useState<AlumnoBusqueda | null>(null);
  const historial = useCarga(() => (alumno ? pedir<Historial>(`/tutoria/alumnos/${alumno.id}`) : Promise.resolve(null)), [alumno?.id]);
  const accion = useAccion();
  const [c, setC] = useState({ tipo: 'incidencia', gravedad: 'leve', detalle: '', avisar_padre: false });
  const [e, setE] = useState({ participantes: '', acuerdos: '', confidencial: false });
  const registrar = puede(colegio, 'cal.conducta.registrar');

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Académico`} titulo="Tutoría y conducta" descripcion="Incidencias, méritos y entrevistas. Las entrevistas confidenciales solo las ven quien las registró, psicología y dirección." />
      <Aviso tipo="error">{accion.error ?? historial.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {!alumno ? (
        <div className="tarjeta" style={{ maxWidth: 640 }}>
          <Campo etiqueta="Buscar alumno" ayuda="Apellidos, nombres o DNI" ancho><input value={q} onChange={(ev) => setQ(ev.target.value)} /></Campo>
          <div style={{ display: 'grid', gap: 4 }}>
            {busqueda.datos?.data.slice(0, 10).map((a) => <button key={a.id} type="button" className="boton boton-borde" style={{ justifyContent: 'flex-start' }} onClick={() => setAlumno(a)}>{a.apellido_paterno} {a.apellido_materno}, {a.nombres} {a.seccion ? `· ${a.seccion}` : ''}</button>)}
          </div>
        </div>
      ) : (
        <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
          <p><b>{alumno.apellido_paterno} {alumno.apellido_materno}, {alumno.nombres}</b> <button type="button" className="boton boton-chico boton-borde" onClick={() => setAlumno(null)}>Cambiar</button></p>
          {registrar && (
            <div className="rejilla">
              <div className="tarjeta">
                <h2>Conducta o mérito</h2>
                <div className="campos">
                  <Campo etiqueta="Tipo"><select value={c.tipo} onChange={(ev) => setC({ ...c, tipo: ev.target.value })}><option value="incidencia">Incidencia</option><option value="merito">Mérito</option></select></Campo>
                  {c.tipo === 'incidencia' && <Campo etiqueta="Gravedad"><select value={c.gravedad} onChange={(ev) => setC({ ...c, gravedad: ev.target.value })}><option value="leve">Leve</option><option value="moderada">Moderada</option><option value="grave">Grave</option></select></Campo>}
                </div>
                <Campo etiqueta="Detalle" ancho><textarea className="control" style={{ width: '100%', height: 80, padding: 8 }} value={c.detalle} onChange={(ev) => setC({ ...c, detalle: ev.target.value })} /></Campo>
                <label className="casilla"><input type="checkbox" checked={c.avisar_padre} onChange={(ev) => setC({ ...c, avisar_padre: ev.target.checked })} /> Avisar a la familia</label>
                <div className="acciones"><button type="button" className="boton" disabled={c.detalle.trim().length < 5 || accion.enviando} onClick={() => accion.ejecutar(async () => {
                  historial.setDatos(await pedir<Historial>(`/tutoria/alumnos/${alumno.id}/conducta`, { metodo: 'POST', cuerpo: c }));
                  setC({ ...c, detalle: '' });
                }, 'Registrado.')}>Registrar</button></div>
              </div>
              <div className="tarjeta">
                <h2>Entrevista de tutoría</h2>
                <Campo etiqueta="Participantes" ancho><input value={e.participantes} placeholder="Tutor(a), madre y alumno" onChange={(ev) => setE({ ...e, participantes: ev.target.value })} /></Campo>
                <Campo etiqueta="Acuerdos" ancho><textarea className="control" style={{ width: '100%', height: 80, padding: 8 }} value={e.acuerdos} onChange={(ev) => setE({ ...e, acuerdos: ev.target.value })} /></Campo>
                <label className="casilla"><input type="checkbox" checked={e.confidencial} onChange={(ev) => setE({ ...e, confidencial: ev.target.checked })} /> Confidencial</label>
                <div className="acciones"><button type="button" className="boton" disabled={!e.participantes.trim() || e.acuerdos.trim().length < 5 || accion.enviando} onClick={() => accion.ejecutar(async () => {
                  historial.setDatos(await pedir<Historial>(`/tutoria/alumnos/${alumno.id}/entrevistas`, { metodo: 'POST', cuerpo: e }));
                  setE({ participantes: '', acuerdos: '', confidencial: false });
                }, 'Entrevista registrada.')}>Registrar</button></div>
              </div>
            </div>
          )}
          {historial.datos && (
            <div className="tarjeta">
              <h2>Historial</h2>
              {historial.datos.conducta.map((x) => (
                <p key={`c${x.id}`}><span className={`insignia ${x.tipo === 'merito' ? 'insignia-ok' : 'insignia-rojo'}`}>{x.tipo === 'merito' ? 'Mérito' : `Incidencia ${x.gravedad ?? ''}`}</span> {fecha(x.fecha)} · {x.detalle} <small className="ayuda">· {x.registrado_por}{x.notificado_padre_at ? ' · familia avisada' : ''}</small></p>
              ))}
              {historial.datos.entrevistas.map((x) => (
                <p key={`e${x.id}`}><span className="insignia">{x.confidencial ? 'Entrevista confidencial' : 'Entrevista'}</span> {fecha(x.fecha)} · {x.participantes}: {x.acuerdos}</p>
              ))}
              {!historial.datos.conducta.length && !historial.datos.entrevistas.length && <p className="vacio">Sin registros.</p>}
            </div>
          )}
        </div>
      )}
    </>
  );
}

/** EDUN-103 · Alertas de riesgo con seguimiento. */
export function AlertasRiesgo() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [filtro, setFiltro] = useState('activas');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Alerta[] }>(`/alertas?estado=${filtro}`), [filtro]);
  const accion = useAccion();
  const [textos, setTextos] = useState<Record<number, string>>({});
  const gestiona = puede(colegio, 'cal.alertas.gestionar');
  const seguir = (a: Alerta, est: string) => accion.ejecutar(async () => {
    await pedir(`/alertas/${a.id}`, { metodo: 'PATCH', cuerpo: { estado: est, accion: textos[a.id] } });
    setTextos({ ...textos, [a.id]: '' });
    recargar();
  }, est === 'cerrada' ? 'Alerta cerrada.' : 'Seguimiento registrado.');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Académico`} titulo="Alertas de riesgo" descripcion="Se evalúan cada mañana: C en 2 o más competencias, más de 3 faltas en el mes y entregas pendientes en el aula.">
        {gestiona && <button type="button" className="boton boton-borde" onClick={() => accion.ejecutar(async () => { const r = await pedir<{ creadas: number }>('/alertas/evaluacion', { metodo: 'POST' }); recargar(); accion.setOk(`${r.creadas} alertas nuevas.`); })}>Evaluar ahora</button>}
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="filtros">
        <Campo etiqueta="Estado"><select value={filtro} onChange={(e) => setFiltro(e.target.value)}><option value="activas">Abiertas y en seguimiento</option><option value="cerrada">Cerradas</option></select></Campo>
      </div>
      <div style={{ display: 'grid', gap: 12, gridTemplateColumns: 'minmax(0, 1fr)' }}>
        {datos?.data.map((a) => (
          <div key={a.id} className="tarjeta">
            <p><span className={`insignia ${a.nivel === 'alta' ? 'insignia-rojo' : 'insignia-ambar'}`}>{a.nombre_motivo}</span> <b>{a.alumno}</b> {a.seccion && `· ${a.seccion}`} <small className="ayuda">· {fechaHora(a.created_at)} · {a.estado.replace('_', ' ')}</small></p>
            <p>{a.detalle}</p>
            {a.accion && <p className="ayuda" style={{ whiteSpace: 'pre-wrap' }}>{a.accion}</p>}
            {gestiona && a.estado !== 'cerrada' && (
              <div className="filtros" style={{ alignItems: 'end' }}>
                <Campo etiqueta="Acción realizada"><input value={textos[a.id] ?? ''} onChange={(e) => setTextos({ ...textos, [a.id]: e.target.value })} /></Campo>
                <button type="button" className="boton boton-chico boton-borde" disabled={(textos[a.id] ?? '').trim().length < 5} onClick={() => seguir(a, 'en_seguimiento')}>En seguimiento</button>
                <button type="button" className="boton boton-chico" disabled={(textos[a.id] ?? '').trim().length < 5} onClick={() => seguir(a, 'cerrada')}>Cerrar</button>
              </div>
            )}
          </div>
        ))}
        {datos?.data.length === 0 && <p className="vacio">No hay alertas.</p>}
      </div>
    </>
  );
}
