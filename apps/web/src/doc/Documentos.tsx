import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Pestanas, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Institucional = { id: number; tipo: string; tipo_nombre: string; nombre: string; version: string; publicado_at: string; requiere_lectura: boolean; vigente: boolean; lecturas: number; familias: number };
type Autorizacion = { id: number; titulo: string; vence: string; creado_por: string; secciones: string[]; total: number; aceptan: number; no_aceptan: number; pendientes: number };
type Seguimiento = Autorizacion & { contenido: string; alumnos: { alumno_id: number; alumno: string; seccion: string; estado: 'acepta' | 'no_acepta' | 'pendiente'; apoderado: string | null; firmado_at: string | null }[] };
type Vencimiento = { id: number | null; titular_tipo: 'alumno' | 'personal'; titular: string; categoria: string; nombre: string; vence: string; vencimiento: { codigo: string; texto: string } };

const FIRMA = { acepta: { texto: 'Acepta', clase: 'insignia-ok' }, no_acepta: { texto: 'No acepta', clase: 'insignia-rojo' }, pendiente: { texto: 'Pendiente', clase: 'insignia-ambar' } };

/** S26 · Documentos del colegio: institucionales con lectura, autorizaciones a padres y vencimientos. */
export function DocumentosColegio() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const opciones: ['institucionales' | 'autorizaciones' | 'vencimientos', string][] = [];
  if (puede(colegio, 'doc.institucionales.ver', 'doc.institucionales.publicar')) opciones.push(['institucionales', 'Documentos institucionales']);
  if (puede(colegio, 'doc.autorizaciones.ver')) opciones.push(['autorizaciones', 'Autorizaciones a padres']);
  if (puede(colegio, 'doc.expedientes.ver')) opciones.push(['vencimientos', 'Vencimientos de expedientes']);
  const [pestana, setPestana] = useState(opciones[0]?.[0] ?? 'institucionales');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Documentos`} titulo="Documentos del colegio" descripcion="Reglamento, PEI y normas con confirmación de lectura; autorizaciones que firman los padres en la app; y documentos de expedientes por vencer." />
      <Pestanas opciones={opciones} actual={pestana} alCambiar={setPestana} />
      {pestana === 'institucionales' && <Institucionales />}
      {pestana === 'autorizaciones' && <Autorizaciones />}
      {pestana === 'vencimientos' && <Vencimientos />}
    </>
  );
}

function Institucionales() {
  const { pedir, estado } = useSesion();
  const publica = puede(estado!.colegio_activo!, 'doc.institucionales.publicar');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Institucional[]; tipos: Record<string, string> }>('/documentos/institucionales'));
  const [f, setF] = useState(() => ({ tipo: 'reglamento', nombre: '', version: String(new Date().getFullYear()), requiere_lectura: true }));
  const [archivo, setArchivo] = useState<File | null>(null);
  const [lecturas, setLecturas] = useState<{ doc: Institucional; filas: { user_id: number; nombre: string; leido_at: string | null }[] } | null>(null);
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {publica && datos && (
        <form className="tarjeta" onSubmit={(e) => {
          e.preventDefault();
          void accion.ejecutar(async () => {
            const cuerpo = new FormData();
            Object.entries(f).forEach(([k, v]) => cuerpo.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : v));
            cuerpo.append('archivo', archivo!);
            await pedir('/documentos/institucionales', { metodo: 'POST', cuerpo });
            setArchivo(null);
            (e.target as HTMLFormElement).reset();
            recargar();
            accion.setOk('Publicado. Las familias recibieron el aviso en la app.');
          });
        }}>
          <h2>Publicar una versión</h2>
          <div className="campos">
            <Campo etiqueta="Tipo"><select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value })}>{Object.entries(datos.tipos).map(([v, n]) => <option key={v} value={v}>{n}</option>)}</select></Campo>
            <Campo etiqueta="Nombre" ayuda="Con el mismo tipo y nombre, la versión anterior queda como histórica."><input value={f.nombre} onChange={(e) => setF({ ...f, nombre: e.target.value })} maxLength={150} required /></Campo>
            <Campo etiqueta="Versión"><input value={f.version} onChange={(e) => setF({ ...f, version: e.target.value })} maxLength={20} required /></Campo>
            <Campo etiqueta="Documento (PDF)"><input type="file" accept="application/pdf" onChange={(e) => setArchivo(e.target.files?.[0] ?? null)} required /></Campo>
            <label className="campo"><span><input type="checkbox" checked={f.requiere_lectura} onChange={(e) => setF({ ...f, requiere_lectura: e.target.checked })} /> Pedir confirmación de lectura a las familias</span></label>
          </div>
          <div className="acciones"><button type="submit" className="boton" disabled={accion.enviando || !archivo || f.nombre.trim().length < 3}>Publicar</button></div>
        </form>
      )}
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">Aún no hay documentos publicados.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Documento</th><th>Versión</th><th>Publicado</th><th>Lectura</th><th /></tr></thead><tbody>
          {datos.data.map((d) => (
            <tr key={d.id}>
              <td>{d.nombre}<br /><small className="ayuda">{d.tipo_nombre}</small></td>
              <td>{d.version} {d.vigente ? <span className="insignia insignia-ok">Vigente</span> : <span className="insignia insignia-gris">Histórica</span>}</td>
              <td>{fechaHora(d.publicado_at)}</td>
              <td>{d.requiere_lectura ? `${d.lecturas} de ${d.familias} familias` : 'No se pide'}</td>
              <td><div className="acciones">
                <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => window.open((await pedir<{ url: string }>(`/documentos/institucionales/${d.id}/archivo`)).url, '_blank', 'noopener'))}>Ver</button>
                {d.requiere_lectura && <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => setLecturas({ doc: d, filas: (await pedir<{ data: { user_id: number; nombre: string; leido_at: string | null }[] }>(`/documentos/institucionales/${d.id}/lecturas`)).data }))}>Quién leyó</button>}
              </div></td>
            </tr>
          ))}
        </tbody></table></div></div>
      )}
      {lecturas && (
        <Dialogo titulo={`Lectura: ${lecturas.doc.nombre} (${lecturas.doc.version})`} alCerrar={() => setLecturas(null)}>
          <p>{lecturas.filas.filter((l) => l.leido_at).length} de {lecturas.filas.length} familias confirmaron.</p>
          <ul className="lista-simple">{lecturas.filas.map((l) => <li key={l.user_id}>{l.nombre} · {l.leido_at ? `leyó el ${fechaHora(l.leido_at)}` : <span className="insignia insignia-ambar">Falta</span>}</li>)}</ul>
          <div className="acciones">
            {publica && lecturas.doc.vigente && <button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => { const r = await pedir<{ enviados: number }>(`/documentos/institucionales/${lecturas.doc.id}/recordatorio`, { metodo: 'POST' }); accion.setOk(`Recordatorio enviado a ${r.enviados} teléfono(s).`); setLecturas(null); })}>Recordar a quienes faltan</button>}
            <button type="button" className="boton boton-borde" onClick={() => setLecturas(null)}>Cerrar</button>
          </div>
        </Dialogo>
      )}
    </>
  );
}

function Autorizaciones() {
  const { pedir, estado } = useSesion();
  const crea = puede(estado!.colegio_activo!, 'doc.autorizaciones.crear');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Autorizacion[] }>('/autorizaciones'));
  const secciones = useCarga(() => (crea ? pedir<{ data: { id: number; nombre: string }[] }>('/autorizaciones/secciones') : Promise.resolve({ data: [] })));
  const [nueva, setNueva] = useState<{ titulo: string; contenido: string; secciones: number[]; vence: string } | null>(null);
  const [viendo, setViendo] = useState<Seguimiento | null>(null);
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {crea && <div className="acciones"><button type="button" className="boton" onClick={() => setNueva({ titulo: '', contenido: '', secciones: [], vence: '' })}>Nueva autorización</button></div>}
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">Aún no hay autorizaciones.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Autorización</th><th>Secciones</th><th>Vence</th><th>Firmas</th><th /></tr></thead><tbody>
          {datos.data.map((a) => (
            <tr key={a.id}>
              <td>{a.titulo}<br /><small className="ayuda">{a.creado_por}</small></td>
              <td>{a.secciones.join(', ')}</td>
              <td>{fecha(a.vence)}</td>
              <td><span className="insignia insignia-ok">{a.aceptan} sí</span> <span className="insignia insignia-rojo">{a.no_aceptan} no</span> <span className="insignia insignia-ambar">{a.pendientes} pendientes</span></td>
              <td><button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => setViendo(await pedir<Seguimiento>(`/autorizaciones/${a.id}`)))}>Seguimiento</button></td>
            </tr>
          ))}
        </tbody></table></div></div>
      )}
      {nueva && (
        <Dialogo titulo="Nueva autorización" alCerrar={() => setNueva(null)}>
          <Aviso tipo="error">{accion.error}</Aviso>
          <div className="campos">
            <Campo etiqueta="Título" ancho><input value={nueva.titulo} onChange={(e) => setNueva({ ...nueva, titulo: e.target.value })} maxLength={150} placeholder="Ej.: Visita al museo de ciencias" /></Campo>
            <Campo etiqueta="Texto que firma la familia" ancho><textarea rows={5} value={nueva.contenido} onChange={(e) => setNueva({ ...nueva, contenido: e.target.value })} maxLength={5000} placeholder="Fecha, lugar, hora de salida y regreso, costo y responsables." /></Campo>
            <Campo etiqueta="Firmar hasta"><input type="date" value={nueva.vence} onChange={(e) => setNueva({ ...nueva, vence: e.target.value })} /></Campo>
          </div>
          <h3>Secciones</h3>
          <div className="casillas">
            {(secciones.datos?.data ?? []).map((s) => (
              <label key={s.id} className="casilla"><input type="checkbox" checked={nueva.secciones.includes(s.id)} onChange={(e) => setNueva({ ...nueva, secciones: e.target.checked ? [...nueva.secciones, s.id] : nueva.secciones.filter((x) => x !== s.id) })} /> {s.nombre}</label>
            ))}
          </div>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando || !nueva.secciones.length || !nueva.vence || nueva.titulo.trim().length < 5 || nueva.contenido.trim().length < 10} onClick={() => accion.ejecutar(async () => {
              const r = await pedir<Autorizacion>('/autorizaciones', { metodo: 'POST', cuerpo: nueva });
              setNueva(null);
              recargar();
              accion.setOk(`Enviada a las familias de ${r.total} alumno(s).`);
            })}>Enviar a las familias</button>
            <button type="button" className="boton boton-borde" onClick={() => setNueva(null)}>Cancelar</button>
          </div>
        </Dialogo>
      )}
      {viendo && (
        <Dialogo titulo={viendo.titulo} alCerrar={() => setViendo(null)}>
          <p className="ayuda" style={{ whiteSpace: 'pre-wrap' }}>{viendo.contenido}</p>
          <div className="tabla-caja"><table className="tabla"><thead><tr><th>Alumno</th><th>Sección</th><th>Respuesta</th><th /></tr></thead><tbody>
            {viendo.alumnos.map((al) => (
              <tr key={al.alumno_id}>
                <td>{al.alumno}</td><td>{al.seccion}</td>
                <td><span className={`insignia ${FIRMA[al.estado].clase}`}>{FIRMA[al.estado].texto}</span>{al.firmado_at && <><br /><small className="ayuda">{al.apoderado} · {fechaHora(al.firmado_at)}</small></>}</td>
                <td>{crea && al.estado !== 'pendiente' && <button type="button" className="boton boton-borde boton-chico" onClick={() => window.confirm('¿Anular esta firma? La familia tendrá que volver a firmar.') && accion.ejecutar(async () => { await pedir(`/autorizaciones/${viendo.id}/firmas/${al.alumno_id}`, { metodo: 'DELETE' }); setViendo(await pedir<Seguimiento>(`/autorizaciones/${viendo.id}`)); recargar(); })}>Anular firma</button>}</td>
              </tr>
            ))}
          </tbody></table></div>
          <div className="acciones">
            {crea && viendo.pendientes > 0 && <button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => { const r = await pedir<{ enviados: number }>(`/autorizaciones/${viendo.id}/recordatorio`, { metodo: 'POST' }); accion.setOk(`Recordatorio enviado a ${r.enviados} teléfono(s).`); })}>Recordar a {viendo.pendientes} pendiente(s)</button>}
            <button type="button" className="boton boton-borde" onClick={() => setViendo(null)}>Cerrar</button>
          </div>
        </Dialogo>
      )}
    </>
  );
}

function Vencimientos() {
  const { pedir } = useSesion();
  const { datos, error } = useCarga(() => pedir<{ data: Vencimiento[] }>('/expedientes/vencimientos'));
  return (
    <>
      <Aviso tipo="error">{error}</Aviso>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hay documentos vencidos ni por vencer en los próximos 30 días.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Titular</th><th>Documento</th><th>Vence</th></tr></thead><tbody>
          {datos.data.map((d, i) => (
            <tr key={`${d.id ?? 'c'}-${i}`}>
              <td>{d.titular}<br /><small className="ayuda">{d.titular_tipo === 'alumno' ? 'Alumno' : 'Personal'}</small></td>
              <td>{d.nombre}</td>
              <td>{fecha(d.vence)} <span className={`insignia ${d.vencimiento.codigo === 'vencido' ? 'insignia-rojo' : 'insignia-ambar'}`}>{d.vencimiento.texto}</span></td>
            </tr>
          ))}
        </tbody></table></div></div>
      )}
    </>
  );
}

type DocFamilia = { id: number; tipo_nombre: string; nombre: string; version: string; publicado_at: string; requiere_lectura: boolean; leido_at: string | null };
type AutFamilia = { id: number; titulo: string; contenido: string; vence: string; alumno_id: number; alumno: string; estado: 'pendiente' | 'acepta' | 'no_acepta' | 'vencida'; firmado_at: string | null };

/** EDUN-121 / EDUN-122 · La familia lee los documentos del colegio y firma las autorizaciones. */
export function MisDocumentos() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const docs = useCarga(() => pedir<{ data: DocFamilia[] }>('/familia/documentos'));
  const auts = useCarga(() => pedir<{ data: AutFamilia[] }>('/familia/autorizaciones'));
  const accion = useAccion();
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Documentos y autorizaciones" descripcion="Lee el reglamento y las normas del colegio y firma las autorizaciones de tus hijos." />
      <Aviso tipo="error">{docs.error ?? auts.error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tarjeta"><h2>Autorizaciones</h2>
        {(auts.datos?.data ?? []).length === 0 ? <p className="vacio">No tienes autorizaciones por firmar.</p> : auts.datos!.data.map((a) => (
          <div key={`${a.id}-${a.alumno_id}`} className="tarjeta">
            <h3>{a.titulo} · {a.alumno}</h3>
            <p style={{ whiteSpace: 'pre-wrap' }}>{a.contenido}</p>
            {a.estado === 'pendiente' ? (
              <div className="acciones">
                <span className="ayuda">Firma hasta el {fecha(a.vence)}.</span>
                <button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => { await pedir(`/familia/autorizaciones/${a.id}/firma`, { metodo: 'POST', cuerpo: { alumno_id: a.alumno_id, acepta: true } }); auts.recargar(); accion.setOk('Autorización firmada.'); })}>Autorizo</button>
                <button type="button" className="boton boton-borde" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => { await pedir(`/familia/autorizaciones/${a.id}/firma`, { metodo: 'POST', cuerpo: { alumno_id: a.alumno_id, acepta: false } }); auts.recargar(); accion.setOk('Respuesta registrada: no autorizas.'); })}>No autorizo</button>
              </div>
            ) : <span className={`insignia ${a.estado === 'acepta' ? 'insignia-ok' : a.estado === 'vencida' ? 'insignia-gris' : 'insignia-rojo'}`}>{a.estado === 'acepta' ? 'Autorizaste' : a.estado === 'vencida' ? 'Venció sin firmar' : 'No autorizaste'}{a.firmado_at ? ` · ${fechaHora(a.firmado_at)}` : ''}</span>}
          </div>
        ))}
      </div>
      <div className="tarjeta"><h2>Documentos del colegio</h2>
        {(docs.datos?.data ?? []).length === 0 ? <p className="vacio">El colegio aún no publicó documentos.</p> : (
          <ul className="lista-simple">{docs.datos!.data.map((d) => (
            <li key={d.id}>
              <button type="button" className="enlace" onClick={() => accion.ejecutar(async () => window.open((await pedir<{ url: string }>(`/familia/documentos/${d.id}/archivo`)).url, '_blank', 'noopener'))}>{d.nombre}</button> · {d.tipo_nombre} · versión {d.version}{' '}
              {d.requiere_lectura && (d.leido_at ? <span className="insignia insignia-ok">Leído</span> : <button type="button" className="boton boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/familia/documentos/${d.id}/lectura`, { metodo: 'POST' }); docs.recargar(); })}>Leí y acepto</button>)}
            </li>
          ))}</ul>
        )}
      </div>
    </>
  );
}
