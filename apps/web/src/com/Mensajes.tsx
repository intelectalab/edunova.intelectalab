import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Conversacion = { id: number; asunto: string; alumno: string | null; con: string | null; sin_leer: number; updated_at: string };
type Contacto = { rol: string; nombre: string };
type Mensaje = { id: number; contenido: string; created_at: string; autor: string; mio: boolean; leido: boolean; programado: boolean; adjunto: string | null };
type Detalle = { id: number; asunto: string; cerrada: boolean; mensajes: Mensaje[]; horario: { desde: string; hasta: string } };
type Cita = { id: number; inicio: string; motivo: string; acuerdos: string | null; estado: string; alumno: string; con: string; apoderado: string };

/** EDUN-63 · Mensajería con el tutor o dirección (familia y personal). */
export function Mensajes() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const familia = colegio.roles.includes('apoderado');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Conversacion[]; horario: { desde: string; hasta: string }; contactos: Record<string, Contacto[]> }>('/conversaciones'));
  const [abierta, setAbierta] = useState<number | null>(null);
  const detalle = useCarga(() => (abierta ? pedir<Detalle>(`/conversaciones/${abierta}`) : Promise.resolve(null)), [abierta]);
  const accion = useAccion();
  const [texto, setTexto] = useState('');
  const [adjunto, setAdjunto] = useState<File | null>(null);
  const [nueva, setNueva] = useState<{ alumno_id: string; rol: string; asunto: string; mensaje: string } | null>(null);
  const subir = async () => {
    if (!adjunto) return null;
    const cuerpo = new FormData();
    cuerpo.append('archivo', adjunto);
    return (await pedir<{ id: number }>('/archivos', { metodo: 'POST', cuerpo })).id;
  };
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Comunicación`} titulo="Mensajes" descripcion={datos ? `Horario de atención: ${datos.horario.desde} a ${datos.horario.hasta}. ${familia ? 'Fuera de ese horario tu mensaje se entrega al día siguiente.' : ''}` : undefined}>
        {familia && <button type="button" className="boton" onClick={() => setNueva({ alumno_id: Object.keys(datos?.contactos ?? {})[0] ?? '', rol: 'tutor', asunto: '', mensaje: '' })}>+ Nuevo mensaje</button>}
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error ?? detalle.error}</Aviso>
      <div className="rejilla" style={{ gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 2fr)' }}>
        <div className="tarjeta">
          {datos?.data.map((c) => (
            <button key={c.id} type="button" className={`boton ${abierta === c.id ? '' : 'boton-borde'}`} style={{ width: '100%', justifyContent: 'space-between', marginBottom: 6 }} onClick={() => setAbierta(c.id)}>
              <span style={{ textAlign: 'left' }}>{c.asunto}<br /><small>{c.con}{c.alumno ? ` · ${c.alumno}` : ''}</small></span>
              {c.sin_leer > 0 && <span className="insignia insignia-rojo">{c.sin_leer}</span>}
            </button>
          ))}
          {datos?.data.length === 0 && <p className="vacio">No hay conversaciones.</p>}
        </div>
        <div className="tarjeta">
          {nueva ? (
            <>
              <h2>Nuevo mensaje</h2>
              <div className="campos">
                <Campo etiqueta="Hijo(a)"><select value={nueva.alumno_id} onChange={(e) => setNueva({ ...nueva, alumno_id: e.target.value })}>{Object.keys(datos?.contactos ?? {}).map((id) => <option key={id} value={id}>Alumno {id}</option>)}</select></Campo>
                <Campo etiqueta="Para"><select value={nueva.rol} onChange={(e) => setNueva({ ...nueva, rol: e.target.value })}>{(datos?.contactos[nueva.alumno_id] ?? []).map((c) => <option key={c.rol} value={c.rol}>{c.rol === 'tutor' ? 'Tutor(a)' : 'Dirección'}: {c.nombre}</option>)}</select></Campo>
              </div>
              <Campo etiqueta="Asunto" ancho><input value={nueva.asunto} onChange={(e) => setNueva({ ...nueva, asunto: e.target.value })} /></Campo>
              <Campo etiqueta="Mensaje" ancho><textarea className="control" style={{ width: '100%', height: 120, padding: 8 }} value={nueva.mensaje} onChange={(e) => setNueva({ ...nueva, mensaje: e.target.value })} /></Campo>
              <label className="boton boton-borde">{adjunto ? adjunto.name : 'Adjuntar archivo'}<input type="file" hidden onChange={(e) => setAdjunto(e.target.files?.[0] ?? null)} /></label>
              <div className="acciones"><button type="button" className="boton" disabled={!nueva.asunto.trim() || !nueva.mensaje.trim() || accion.enviando} onClick={() => accion.ejecutar(async () => {
                const r = await pedir<Detalle>('/conversaciones', { metodo: 'POST', cuerpo: { ...nueva, alumno_id: Number(nueva.alumno_id), archivo_id: await subir() } });
                setNueva(null);
                setAdjunto(null);
                recargar();
                setAbierta(r.id);
              })}>Enviar</button></div>
            </>
          ) : detalle.datos ? (
            <>
              <h2>{detalle.datos.asunto}</h2>
              <div style={{ display: 'grid', gap: 8, maxHeight: 420, overflow: 'auto' }}>
                {detalle.datos.mensajes.map((m) => (
                  <div key={m.id} className="aviso" style={{ marginLeft: m.mio ? 40 : 0, marginRight: m.mio ? 0 : 40, background: m.mio ? 'var(--en-azul-suave)' : 'var(--en-suave)', color: 'var(--en-texto)' }}>
                    <small className="ayuda">{m.autor} · {fechaHora(m.created_at)}{m.mio ? (m.programado ? ' · se entrega al abrir el horario' : m.leido ? ' · leído ✓✓' : ' · enviado ✓') : ''}</small>
                    <div style={{ whiteSpace: 'pre-wrap' }}>{m.contenido}</div>
                    {m.adjunto && <small>📎 {m.adjunto}</small>}
                  </div>
                ))}
              </div>
              {!detalle.datos.cerrada && (
                <>
                  <textarea className="control" aria-label="Tu mensaje" style={{ width: '100%', height: 80, padding: 8, marginTop: 10 }} value={texto} onChange={(e) => setTexto(e.target.value)} />
                  <div className="acciones">
                    <label className="boton boton-borde">{adjunto ? adjunto.name : 'Adjuntar'}<input type="file" hidden onChange={(e) => setAdjunto(e.target.files?.[0] ?? null)} /></label>
                    <button type="button" className="boton" disabled={!texto.trim() || accion.enviando} onClick={() => accion.ejecutar(async () => {
                      detalle.setDatos(await pedir<Detalle>(`/conversaciones/${abierta}/mensajes`, { metodo: 'POST', cuerpo: { contenido: texto, archivo_id: await subir() } }));
                      setTexto('');
                      setAdjunto(null);
                    })}>Enviar</button>
                  </div>
                </>
              )}
            </>
          ) : <p className="vacio">Elige una conversación.</p>}
        </div>
      </div>
    </>
  );
}

/** EDUN-65 · Citas: la familia las pide; el colegio confirma y registra acuerdos. */
export function Citas() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const familia = colegio.roles.includes('apoderado');
  const { datos, setDatos, error } = useCarga(() => pedir<{ data: Cita[] }>(`/citas${familia ? '?familia=1' : ''}`), [familia]);
  const contactos = useCarga(() => (familia ? pedir<{ contactos: Record<string, Contacto[]> }>('/conversaciones') : Promise.resolve({ contactos: {} })), [familia]);
  const accion = useAccion();
  const [f, setF] = useState({ alumno_id: '', rol: 'tutor', inicio: '', motivo: '' });
  const [acuerdos, setAcuerdos] = useState<Record<number, string>>({});
  const gestionar = (c: Cita, est: string) => accion.ejecutar(async () => setDatos(await pedir<{ data: Cita[] }>(`/citas/${c.id}`, { metodo: 'PATCH', cuerpo: { estado: est, acuerdos: acuerdos[c.id] || null } })));
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Comunicación`} titulo="Citas" descripcion={familia ? 'Pide una cita con el tutor o la dirección.' : 'Confirma las citas y registra los acuerdos.'} />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {familia && (
        <div className="tarjeta" style={{ marginBottom: 14 }}>
          <div className="campos">
            <Campo etiqueta="Hijo(a)"><select value={f.alumno_id} onChange={(e) => setF({ ...f, alumno_id: e.target.value })}><option value="">Elige</option>{Object.keys(contactos.datos?.contactos ?? {}).map((id) => <option key={id} value={id}>Alumno {id}</option>)}</select></Campo>
            <Campo etiqueta="Con"><select value={f.rol} onChange={(e) => setF({ ...f, rol: e.target.value })}><option value="tutor">Tutor(a)</option><option value="direccion">Dirección</option></select></Campo>
            <Campo etiqueta="Fecha y hora propuesta"><input type="datetime-local" value={f.inicio} onChange={(e) => setF({ ...f, inicio: e.target.value })} /></Campo>
            <Campo etiqueta="Motivo"><input value={f.motivo} maxLength={200} onChange={(e) => setF({ ...f, motivo: e.target.value })} /></Campo>
          </div>
          <div className="acciones"><button type="button" className="boton" disabled={!f.alumno_id || !f.inicio || !f.motivo.trim() || accion.enviando} onClick={() => accion.ejecutar(async () => {
            setDatos(await pedir<{ data: Cita[] }>('/citas', { metodo: 'POST', cuerpo: { ...f, alumno_id: Number(f.alumno_id), inicio: new Date(f.inicio).toISOString() } }));
            setF({ alumno_id: '', rol: 'tutor', inicio: '', motivo: '' });
          }, 'Cita solicitada.')}>Solicitar cita</button></div>
        </div>
      )}
      <div className="tarjeta">
        <div className="tabla-caja">
          <table className="tabla"><thead><tr><th>Fecha</th><th>Alumno</th><th>{familia ? 'Con' : 'Familia'}</th><th>Motivo</th><th>Estado</th><th /></tr></thead><tbody>
            {datos?.data.map((c) => (
              <tr key={c.id}>
                <td>{fechaHora(c.inicio)}</td><td>{c.alumno}</td><td>{familia ? c.con : c.apoderado}</td><td>{c.motivo}{c.acuerdos && <><br /><small className="ayuda">Acuerdos: {c.acuerdos}</small></>}</td>
                <td><span className={`insignia ${c.estado === 'realizada' || c.estado === 'confirmada' ? 'insignia-ok' : c.estado === 'cancelada' ? 'insignia-rojo' : 'insignia-ambar'}`}>{c.estado}</span></td>
                <td>{!familia && c.estado === 'solicitada' && <><button type="button" className="boton boton-chico" onClick={() => gestionar(c, 'confirmada')}>Confirmar</button> <button type="button" className="boton boton-chico boton-borde" onClick={() => gestionar(c, 'cancelada')}>Cancelar</button></>}
                  {!familia && c.estado === 'confirmada' && <div style={{ display: 'flex', gap: 4 }}><input aria-label="Acuerdos" placeholder="Acuerdos" value={acuerdos[c.id] ?? ''} onChange={(e) => setAcuerdos({ ...acuerdos, [c.id]: e.target.value })} /><button type="button" className="boton boton-chico" disabled={!(acuerdos[c.id] ?? '').trim()} onClick={() => gestionar(c, 'realizada')}>Realizada</button></div>}</td>
              </tr>
            ))}
          </tbody></table>
          {datos?.data.length === 0 && <p className="vacio">No hay citas.</p>}
        </div>
      </div>
    </>
  );
}
