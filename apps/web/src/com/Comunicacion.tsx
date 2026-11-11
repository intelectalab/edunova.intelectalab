import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from '../adm/anios';

type Comunicado = { id: number; titulo: string; contenido: string; estado: string; canales: string[]; requiere_confirmacion: boolean; enviado_at: string | null; programado_para: string | null; destinatarios: number; leidos: number; autor: string; motivo_anulacion: string | null };
type Lectura = { por_seccion: { seccion: string; total: number; leidos: number; confirmados: number; porcentaje: number }[]; no_leidos: { id: number; alumno: string; apoderado: string; seccion: string | null }[] };
type ComFamilia = { id: number; comunicado_id: number; titulo: string; contenido: string; enviado_at: string; requiere_confirmacion: boolean; leido_at: string | null; confirmado_at: string | null; alumno_id: number; alumno: string };
type Evento = { id: number; titulo: string; descripcion: string | null; inicio: string; fin: string | null; tipo: string; alcance: { niveles?: string[]; secciones?: number[] } };
type Reclamo = { id: number; numero: string; tipo: string; consumidor_nombre: string; consumidor_email: string; detalle: string; pedido: string; fecha_limite: string; estado: string; dias_restantes: number | null; respuesta: string | null; created_at: string };

function useSecciones() {
  const { pedir } = useSesion();
  const { anio } = useAnioEscolar();
  return useCarga(() => (anio ? pedir<{ data: { id: number; nombre: string }[] }>(`/asistencia/secciones?anio_escolar_id=${anio}`) : Promise.resolve({ data: [] })), [anio]).datos?.data ?? [];
}

function NuevoComunicado({ alCrear }: { alCrear: () => void }) {
  const { pedir } = useSesion();
  const secciones = useSecciones();
  const accion = useAccion();
  const [f, setF] = useState({ titulo: '', contenido: '', tipo: 'colegio', ids: [] as number[], niveles: [] as string[], con_deuda: false, canales: ['app'], requiere_confirmacion: false, programado_para: '' });
  const [previa, setPrevia] = useState<{ familias: number } | null>(null);
  const alcance = { tipo: f.tipo, ids: f.ids, niveles: f.niveles, con_deuda: f.con_deuda };
  return (
    <>
      <Campo etiqueta="Título" ancho><input value={f.titulo} maxLength={200} onChange={(e) => setF({ ...f, titulo: e.target.value })} /></Campo>
      <Campo etiqueta="Mensaje" ancho><textarea className="control" style={{ width: '100%', height: 140, padding: 10 }} value={f.contenido} onChange={(e) => setF({ ...f, contenido: e.target.value })} /></Campo>
      <div className="campos">
        <Campo etiqueta="Alcance">
          <select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value, ids: [], niveles: [] })}>
            <option value="colegio">Todo el colegio</option><option value="nivel">Nivel</option><option value="seccion">Secciones</option>
          </select>
        </Campo>
        <Campo etiqueta="Programar (opcional)"><input type="datetime-local" value={f.programado_para} onChange={(e) => setF({ ...f, programado_para: e.target.value })} /></Campo>
      </div>
      {f.tipo === 'nivel' && ['inicial', 'primaria', 'secundaria'].map((n) => (
        <label key={n} className="casilla"><input type="checkbox" checked={f.niveles.includes(n)} onChange={(e) => setF({ ...f, niveles: e.target.checked ? [...f.niveles, n] : f.niveles.filter((x) => x !== n) })} /> {n}</label>
      ))}
      {f.tipo === 'seccion' && <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>{secciones.map((s) => (
        <label key={s.id} className="casilla"><input type="checkbox" checked={f.ids.includes(s.id)} onChange={(e) => setF({ ...f, ids: e.target.checked ? [...f.ids, s.id] : f.ids.filter((x) => x !== s.id) })} /> {s.nombre}</label>
      ))}</div>}
      <label className="casilla"><input type="checkbox" checked={f.con_deuda} onChange={(e) => setF({ ...f, con_deuda: e.target.checked })} /> Solo familias con deuda vencida</label>
      <p className="etiqueta">Canales</p>
      {[['app', 'App (gratis)'], ['whatsapp', 'WhatsApp (tiene costo)'], ['correo', 'Correo']].map(([k, v]) => (
        <label key={k} className="casilla"><input type="checkbox" checked={f.canales.includes(k)} onChange={(e) => setF({ ...f, canales: e.target.checked ? [...f.canales, k] : f.canales.filter((x) => x !== k) })} /> {v}</label>
      ))}
      <label className="casilla"><input type="checkbox" checked={f.requiere_confirmacion} onChange={(e) => setF({ ...f, requiere_confirmacion: e.target.checked })} /> Pedir confirmación de lectura</label>
      {previa && <p className="ayuda">Lo recibirán {previa.familias} familias.</p>}
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="button" className="boton boton-borde" onClick={() => accion.ejecutar(async () => setPrevia(await pedir<{ familias: number }>('/comunicados/vista-previa', { metodo: 'POST', cuerpo: { alcance } })))}>¿Cuántos lo recibirán?</button>
        <button type="button" className="boton" disabled={!f.titulo.trim() || !f.contenido.trim() || !f.canales.length || accion.enviando} onClick={() => accion.ejecutar(async () => {
          await pedir('/comunicados', { metodo: 'POST', cuerpo: { titulo: f.titulo, contenido: f.contenido, alcance, canales: f.canales, requiere_confirmacion: f.requiere_confirmacion, programado_para: f.programado_para ? new Date(f.programado_para).toISOString() : null } });
          alCrear();
        })}>{f.programado_para ? 'Programar' : 'Enviar ahora'}</button>
      </div>
    </>
  );
}

/** EDUN-60 / EDUN-61 · Comunicados segmentados con lectura y confirmación. */
export function Comunicados() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, setDatos, error, recargar } = useCarga(() => pedir<{ data: Comunicado[] }>('/comunicados'));
  const accion = useAccion();
  const [nuevo, setNuevo] = useState(false);
  const [lectura, setLectura] = useState<{ c: Comunicado; l: Lectura } | null>(null);
  const envia = puede(colegio, 'com.comunicados.enviar');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Comunicación`} titulo="Comunicados" descripcion="Por colegio, nivel o sección, por app, WhatsApp o correo. Un enviado no se edita: se anula y se envía otro.">
        {envia && <button type="button" className="boton" onClick={() => setNuevo(true)}>+ Comunicado</button>}
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tarjeta">
        <div className="tabla-caja">
          <table className="tabla">
            <thead><tr><th>Comunicado</th><th>Canales</th><th>Estado</th><th>Lectura</th><th /></tr></thead>
            <tbody>
              {datos?.data.map((c) => (
                <tr key={c.id}>
                  <td><b>{c.titulo}</b><br /><small className="ayuda">{c.autor} · {c.enviado_at ? fechaHora(c.enviado_at) : c.programado_para ? `programado ${fechaHora(c.programado_para)}` : ''}</small></td>
                  <td>{c.canales.join(', ')}</td>
                  <td><span className={`insignia ${c.estado === 'enviado' ? 'insignia-ok' : c.estado === 'anulado' ? 'insignia-rojo' : 'insignia-ambar'}`}>{c.estado}</span>{c.motivo_anulacion && <><br /><small className="ayuda">{c.motivo_anulacion}</small></>}</td>
                  <td>{c.destinatarios ? `${c.leidos} de ${c.destinatarios}` : '—'}</td>
                  <td>
                    {c.estado === 'enviado' && <button type="button" className="boton boton-chico boton-borde" onClick={() => accion.ejecutar(async () => setLectura({ c, l: await pedir<Lectura>(`/comunicados/${c.id}/lectura`) }))}>Lectura</button>}{' '}
                    {envia && c.estado !== 'anulado' && <button type="button" className="boton boton-chico boton-borde" onClick={() => { const m = window.prompt('Motivo de la anulación'); if (m && m.trim().length >= 5) accion.ejecutar(async () => setDatos(await pedir<{ data: Comunicado[] }>(`/comunicados/${c.id}/anulacion`, { metodo: 'POST', cuerpo: { motivo: m } })), 'Comunicado anulado.'); }}>Anular</button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos?.data.length === 0 && <p className="vacio">Aún no hay comunicados.</p>}
        </div>
      </div>
      {nuevo && <Dialogo titulo="Nuevo comunicado" alCerrar={() => setNuevo(false)}><NuevoComunicado alCrear={() => { setNuevo(false); recargar(); accion.setOk('Comunicado registrado.'); }} /></Dialogo>}
      {lectura && (
        <Dialogo titulo={`Lectura · ${lectura.c.titulo}`} alCerrar={() => setLectura(null)}>
          <table className="tabla"><thead><tr><th>Sección</th><th>Leído</th><th>Confirmado</th></tr></thead><tbody>
            {lectura.l.por_seccion.map((s) => <tr key={s.seccion}><td>{s.seccion}</td><td>{s.leidos}/{s.total} ({s.porcentaje} %)</td><td>{s.confirmados}</td></tr>)}
          </tbody></table>
          {lectura.l.no_leidos.length > 0 && <><h3>Sin leer</h3><ul>{lectura.l.no_leidos.slice(0, 50).map((n) => <li key={n.id}>{n.apoderado} ({n.alumno}, {n.seccion})</li>)}</ul></>}
          {envia && lectura.l.no_leidos.length > 0 && <div className="acciones"><button type="button" className="boton" onClick={() => accion.ejecutar(async () => { const r = await pedir<{ recordados: number }>(`/comunicados/${lectura.c.id}/recordatorio`, { metodo: 'POST' }); accion.setOk(`Recordatorio enviado a ${r.recordados} familias.`); })}>Recordar a quienes no leyeron</button></div>}
        </Dialogo>
      )}
    </>
  );
}

/** EDUN-62 · La familia recibe, lee y confirma los comunicados (historial por hijo). */
export function MisComunicados() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, setDatos, error } = useCarga(() => pedir<{ data: ComFamilia[] }>('/familia/comunicados'));
  const accion = useAccion();
  const [hijo, setHijo] = useState<number | ''>('');
  const hijos = [...new Map((datos?.data ?? []).map((c) => [c.alumno_id, c.alumno])).entries()];
  const marcar = (c: ComFamilia, confirmar: boolean) => accion.ejecutar(async () => setDatos(await pedir<{ data: ComFamilia[] }>(`/familia/comunicados/${c.id}`, { metodo: 'POST', cuerpo: { confirmar } })));
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Comunicados" descripcion="Lo que el colegio te envió, por hijo." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {hijos.length > 1 && <Campo etiqueta="Hijo(a)"><select value={hijo} onChange={(e) => setHijo(Number(e.target.value) || '')}><option value="">Todos</option>{hijos.map(([id, n]) => <option key={id} value={id}>{n}</option>)}</select></Campo>}
      <div style={{ display: 'grid', gap: 12, gridTemplateColumns: 'minmax(0, 1fr)' }}>
        {datos?.data.filter((c) => !hijo || c.alumno_id === hijo).map((c) => (
          <div key={c.id} className="tarjeta" style={{ borderLeft: c.leido_at ? undefined : '4px solid var(--en-primario)' }} onClick={() => !c.leido_at && marcar(c, false)}>
            <p className="ayuda">{fechaHora(c.enviado_at)} · {c.alumno}</p>
            <h3 style={{ margin: '4px 0' }}>{c.titulo}</h3>
            <p style={{ whiteSpace: 'pre-wrap' }}>{c.contenido}</p>
            {c.requiere_confirmacion && (c.confirmado_at ? <span className="insignia insignia-ok">Confirmado {fechaHora(c.confirmado_at)}</span>
              : <button type="button" className="boton boton-chico" onClick={(e) => { e.stopPropagation(); marcar(c, true); }}>Confirmar lectura</button>)}
          </div>
        ))}
        {datos?.data.length === 0 && <p className="vacio">No hay comunicados.</p>}
      </div>
    </>
  );
}

/** EDUN-64 · Agenda del colegio (la familia ve lo de sus hijos). */
export function Agenda() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const familia = !puede(colegio, 'com.agenda.editar') && colegio.roles.includes('apoderado');
  const { datos, setDatos, error } = useCarga(() => pedir<{ data: Evento[] }>(`/agenda${familia ? '?familia=1' : ''}`), [familia]);
  const secciones = useSecciones();
  const accion = useAccion();
  const edita = !familia && puede(colegio, 'com.agenda.editar');
  const [f, setF] = useState({ titulo: '', inicio: '', descripcion: '', niveles: [] as string[], secciones: [] as number[] });
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Comunicación`} titulo="Agenda" descripcion="Eventos del colegio. Las familias reciben un recordatorio el día anterior." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {edita && (
        <div className="tarjeta" style={{ marginBottom: 14 }}>
          <div className="campos">
            <Campo etiqueta="Evento"><input value={f.titulo} onChange={(e) => setF({ ...f, titulo: e.target.value })} /></Campo>
            <Campo etiqueta="Inicio"><input type="datetime-local" value={f.inicio} onChange={(e) => setF({ ...f, inicio: e.target.value })} /></Campo>
            <Campo etiqueta="Detalle"><input value={f.descripcion} onChange={(e) => setF({ ...f, descripcion: e.target.value })} /></Campo>
          </div>
          <p className="ayuda">Alcance (vacío = todo el colegio):</p>
          {['inicial', 'primaria', 'secundaria'].map((n) => <label key={n} className="casilla"><input type="checkbox" checked={f.niveles.includes(n)} onChange={(e) => setF({ ...f, niveles: e.target.checked ? [...f.niveles, n] : f.niveles.filter((x) => x !== n) })} /> {n}</label>)}
          {secciones.map((s) => <label key={s.id} className="casilla"><input type="checkbox" checked={f.secciones.includes(s.id)} onChange={(e) => setF({ ...f, secciones: e.target.checked ? [...f.secciones, s.id] : f.secciones.filter((x) => x !== s.id) })} /> {s.nombre}</label>)}
          <div className="acciones"><button type="button" className="boton" disabled={!f.titulo.trim() || !f.inicio || accion.enviando} onClick={() => accion.ejecutar(async () => {
            setDatos(await pedir<{ data: Evento[] }>('/agenda', { metodo: 'POST', cuerpo: { ...f, inicio: new Date(f.inicio).toISOString() } }));
            setF({ titulo: '', inicio: '', descripcion: '', niveles: [], secciones: [] });
          })}>Publicar evento</button></div>
        </div>
      )}
      <div className="tarjeta">
        {datos?.data.map((e) => (
          <div key={e.id} className="fila-lista" style={{ display: 'flex', justifyContent: 'space-between', gap: 8, padding: '8px 0', borderBottom: '1px solid var(--en-borde)' }}>
            <div><b>{e.titulo}</b> <small className="ayuda">· {fechaHora(e.inicio)}</small>{e.descripcion && <><br /><small>{e.descripcion}</small></>}</div>
            {edita && <button type="button" className="boton boton-chico boton-borde" onClick={() => window.confirm(`¿Eliminar «${e.titulo}»?`) && accion.ejecutar(async () => setDatos(await pedir<{ data: Evento[] }>(`/agenda/${e.id}`, { metodo: 'DELETE' })))}>Eliminar</button>}
          </div>
        ))}
        {datos?.data.length === 0 && <p className="vacio">No hay eventos próximos.</p>}
      </div>
    </>
  );
}

/** EDUN-66 / EDUN-67 · Reclamos del libro virtual con plazo. */
export function Reclamos() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, setDatos, error } = useCarga(() => pedir<{ data: Reclamo[]; plazo_dias: number }>('/reclamos'));
  const accion = useAccion();
  const [resp, setResp] = useState<{ r: Reclamo; texto: string } | null>(null);
  const enlace = `${window.location.origin}${window.location.pathname}#/libro/${colegio.slug}`;
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Comunicación`} titulo="Libro de reclamaciones" descripcion={`Plazo legal de respuesta: ${datos?.plazo_dias ?? 15} días. Recibes una alerta 3 días antes de vencer.`} />
      <Aviso tipo="info">Enlace público para tu web: <a href={enlace} target="_blank" rel="noreferrer">{enlace}</a></Aviso>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <div className="tarjeta">
        <div className="tabla-caja">
          <table className="tabla">
            <thead><tr><th>N.°</th><th>Consumidor</th><th>Detalle</th><th>Plazo</th><th /></tr></thead>
            <tbody>
              {datos?.data.map((r) => (
                <tr key={r.id}>
                  <td><b>{r.numero}</b><br /><small className="ayuda">{r.tipo} · {fecha(r.created_at)}</small></td>
                  <td>{r.consumidor_nombre}<br /><small className="ayuda">{r.consumidor_email}</small></td>
                  <td><small>{r.detalle}</small><br /><small className="ayuda">Pide: {r.pedido}</small></td>
                  <td>{r.estado === 'respondido' ? <span className="insignia insignia-ok">Respondido</span> : <span className={`insignia ${(r.dias_restantes ?? 0) <= 3 ? 'insignia-rojo' : 'insignia-ambar'}`}>{r.dias_restantes} días · {fecha(r.fecha_limite)}</span>}</td>
                  <td>{r.estado === 'abierto' && puede(colegio, 'com.reclamaciones.responder') && <button type="button" className="boton boton-chico" onClick={() => setResp({ r, texto: '' })}>Responder</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos?.data.length === 0 && <p className="vacio">No hay reclamos.</p>}
        </div>
      </div>
      {resp && (
        <Dialogo titulo={`Responder ${resp.r.numero}`} alCerrar={() => setResp(null)}>
          <p className="ayuda">La respuesta se envía al correo del consumidor y queda guardada.</p>
          <textarea className="control" aria-label="Respuesta" style={{ width: '100%', height: 180, padding: 10 }} value={resp.texto} onChange={(e) => setResp({ ...resp, texto: e.target.value })} />
          <div className="acciones"><button type="button" className="boton" disabled={resp.texto.trim().length < 20 || accion.enviando} onClick={() => accion.ejecutar(async () => {
            setDatos({ ...(await pedir<{ data: Reclamo[] }>(`/reclamos/${resp.r.id}/respuesta`, { metodo: 'POST', cuerpo: { respuesta: resp.texto } })), plazo_dias: datos?.plazo_dias ?? 15 });
            setResp(null);
          })}>Enviar respuesta</button></div>
        </Dialogo>
      )}
    </>
  );
}

/** EDUN-68 · Bitácora de notificaciones y costo de WhatsApp por mes. */
export function Bitacora() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [canal, setCanal] = useState('');
  const { datos, error } = useCarga(() => pedir<{ data: { id: number; canal: string; plantilla: string; estado: string; costo: number | null; destino: string | null; created_at: string; usuario: string | null; datos: { titulo?: string } }[]; por_mes: { mes: string; canal: string; envios: number; fallidos: number; costo: number }[] }>(`/notificaciones/bitacora${canal ? `?canal=${canal}` : ''}`), [canal]);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Comunicación`} titulo="Bitácora de notificaciones" descripcion="Estado de cada envío y costo de WhatsApp por mes." />
      <Aviso tipo="error">{error}</Aviso>
      {datos && (
        <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
          <div className="tarjeta">
            <h2>Por mes</h2>
            <table className="tabla"><thead><tr><th>Mes</th><th>Canal</th><th>Envíos</th><th>Fallidos</th><th>Costo</th></tr></thead><tbody>
              {datos.por_mes.map((m) => <tr key={m.mes + m.canal}><td>{m.mes}</td><td>{m.canal}</td><td>{m.envios}</td><td>{m.fallidos}</td><td>{m.canal === 'whatsapp' ? `S/ ${m.costo.toFixed(2)}` : '—'}</td></tr>)}
            </tbody></table>
          </div>
          <div className="tarjeta">
            <Campo etiqueta="Canal"><select value={canal} onChange={(e) => setCanal(e.target.value)}><option value="">Todos</option><option value="push">App</option><option value="whatsapp">WhatsApp</option><option value="email">Correo</option></select></Campo>
            <div className="tabla-caja">
              <table className="tabla"><thead><tr><th>Fecha</th><th>Canal</th><th>Para</th><th>Aviso</th><th>Estado</th></tr></thead><tbody>
                {datos.data.map((n) => <tr key={n.id}><td>{fechaHora(n.created_at)}</td><td>{n.canal}</td><td>{n.usuario ?? n.destino}</td><td>{n.datos?.titulo ?? n.plantilla}</td><td><span className={`insignia ${n.estado === 'fallido' ? 'insignia-rojo' : 'insignia-ok'}`}>{n.estado}</span></td></tr>)}
              </tbody></table>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
