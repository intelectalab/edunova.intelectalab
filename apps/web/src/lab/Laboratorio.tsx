import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Pestanas, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Espacio = { id: number; nombre: string; tipo: string; capacidad: number | null; activo: boolean };
type Reserva = { id: number; fecha: string; hora_inicio: string; hora_fin: string; materiales: string | null; estado: string; espacio: string; docente: string; seccion: string | null };
type Equipo = { id: number; codigo: string; tipo: string; descripcion: string | null; estado: string; prestado_a: string | null; hasta: string | null; vencido: boolean };
type Material = { id: number; nombre: string; unidad: string; stock: number; stock_minimo: number; vence: string | null; ubicacion: string | null; peligroso: boolean; tiene_hoja: boolean; vencido: boolean; por_vencer: boolean; bajo_minimo: boolean };
type Incidencia = { id: number; fecha: string; detalle: string; estado: string; respuesta: string | null; equipo: string | null; reportado_por: string };
const ESTADO_EQUIPO: Record<string, string> = { disponible: 'insignia-ok', prestado: 'insignia-azul', reparacion: 'insignia-ambar', baja: 'insignia-gris' };

/** EDUN-191 a 194 · Laboratorios y talleres: reservas, equipos, materiales y reactivos, incidencias. */
export function Laboratorio() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const opciones: ['reservas' | 'equipos' | 'materiales' | 'incidencias', string][] = [['reservas', 'Reservas']];
  if (puede(colegio, 'lab.equipos.ver')) opciones.push(['equipos', 'Equipos'], ['materiales', 'Materiales y reactivos']);
  opciones.push(['incidencias', 'Incidencias']);
  const [pestana, setPestana] = useState(opciones[0][0]);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Servicios`} titulo="Laboratorios y talleres" descripcion="Reserva de espacios sin cruces, préstamo de equipos con revisión, control de reactivos y seguimiento de incidencias." />
      <Pestanas opciones={opciones} actual={pestana} alCambiar={setPestana} />
      {pestana === 'reservas' && <Reservas />}
      {pestana === 'equipos' && <Equipos />}
      {pestana === 'materiales' && <Materiales />}
      {pestana === 'incidencias' && <Incidencias />}
    </>
  );
}

function Reservas() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const gestiona = puede(colegio, 'lab.reservas.gestionar');
  const base = useCarga(() => pedir<{ data: Espacio[]; secciones: { id: number; nombre: string }[] }>('/laboratorio/espacios'));
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Reserva[] }>('/laboratorio/reservas'));
  const [f, setF] = useState({ lab_espacio_id: '', fecha: '', hora_inicio: '08:00', hora_fin: '09:00', seccion_id: '', materiales: '' });
  const [nuevoEspacio, setNuevoEspacio] = useState('');
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? base.error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {gestiona && <div className="filtros"><Campo etiqueta="Nuevo espacio"><input value={nuevoEspacio} onChange={(e) => setNuevoEspacio(e.target.value)} placeholder="Lab de Ciencias, Sala de cómputo…" /></Campo>
        <button type="button" className="boton boton-borde boton-chico" disabled={nuevoEspacio.trim().length < 2} onClick={() => accion.ejecutar(async () => { await pedir('/laboratorio/espacios', { metodo: 'POST', cuerpo: { nombre: nuevoEspacio, tipo: 'laboratorio' } }); setNuevoEspacio(''); base.recargar(); })}>Agregar</button></div>}
      {puede(colegio, 'lab.reservas.reservar') && base.datos && (
        <div className="tarjeta"><h2>Reservar</h2><div className="campos">
          <Campo etiqueta="Espacio"><select value={f.lab_espacio_id} onChange={(e) => setF({ ...f, lab_espacio_id: e.target.value })}><option value="">Elige…</option>{base.datos.data.filter((e) => e.activo).map((e) => <option key={e.id} value={e.id}>{e.nombre}</option>)}</select></Campo>
          <Campo etiqueta="Fecha"><input type="date" value={f.fecha} onChange={(e) => setF({ ...f, fecha: e.target.value })} /></Campo>
          <Campo etiqueta="Desde"><input type="time" value={f.hora_inicio} onChange={(e) => setF({ ...f, hora_inicio: e.target.value })} /></Campo>
          <Campo etiqueta="Hasta"><input type="time" value={f.hora_fin} onChange={(e) => setF({ ...f, hora_fin: e.target.value })} /></Campo>
          <Campo etiqueta="Sección"><select value={f.seccion_id} onChange={(e) => setF({ ...f, seccion_id: e.target.value })}><option value="">—</option>{base.datos.secciones.map((s) => <option key={s.id} value={s.id}>{s.nombre}</option>)}</select></Campo>
          <Campo etiqueta="Materiales que necesitas" ancho><input value={f.materiales} onChange={(e) => setF({ ...f, materiales: e.target.value })} /></Campo>
        </div>
        <button type="button" className="boton" disabled={!f.lab_espacio_id || !f.fecha || accion.enviando} onClick={() => accion.ejecutar(async () => { await pedir('/laboratorio/reservas', { metodo: 'POST', cuerpo: { ...f, lab_espacio_id: Number(f.lab_espacio_id), seccion_id: f.seccion_id ? Number(f.seccion_id) : null, materiales: f.materiales || null } }); recargar(); accion.setOk('Reservado. El jefe de laboratorio recibió el aviso.'); })}>Reservar</button></div>
      )}
      {datos && (datos.data.length === 0 ? <p className="vacio">No hay reservas esta semana.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Fecha</th><th>Espacio</th><th>Docente</th><th>Materiales</th><th>Estado</th><th /></tr></thead><tbody>
          {datos.data.map((r) => <tr key={r.id}><td>{fecha(r.fecha)} {r.hora_inicio}–{r.hora_fin}</td><td>{r.espacio}</td><td>{r.docente}{r.seccion ? ` · ${r.seccion}` : ''}</td><td>{r.materiales ?? '—'}</td><td>{r.estado}</td>
            <td>{r.estado === 'reservada' && <div className="acciones">{gestiona && <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/laboratorio/reservas/${r.id}/estado`, { metodo: 'POST', cuerpo: { estado: 'realizada' } }); recargar(); })}>Realizada</button>}
              <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/laboratorio/reservas/${r.id}/estado`, { metodo: 'POST', cuerpo: { estado: 'cancelada' } }); recargar(); })}>Cancelar</button></div>}</td></tr>)}
        </tbody></table></div></div>
      ))}
    </>
  );
}

function Equipos() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const base = useCarga(() => pedir<{ personal: { id: number; nombre: string }[]; secciones: { id: number; nombre: string }[] }>('/laboratorio/espacios'));
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Equipo[] }>('/laboratorio/equipos'));
  const [nuevo, setNuevo] = useState({ codigo: '', tipo: '' });
  const [p, setP] = useState({ codigo: '', personal_id: '', hasta: '' });
  const [d, setD] = useState({ codigo: '', resultado: 'bueno', observacion: '' });
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {puede(colegio, 'lab.equipos.gestionar') && <div className="filtros">
        <Campo etiqueta="Código"><input value={nuevo.codigo} onChange={(e) => setNuevo({ ...nuevo, codigo: e.target.value })} /></Campo>
        <Campo etiqueta="Tipo"><input value={nuevo.tipo} onChange={(e) => setNuevo({ ...nuevo, tipo: e.target.value })} placeholder="Microscopio, laptop…" /></Campo>
        <button type="button" className="boton boton-borde boton-chico" disabled={!nuevo.codigo || !nuevo.tipo} onClick={() => accion.ejecutar(async () => { await pedir('/laboratorio/equipos', { metodo: 'POST', cuerpo: nuevo }); setNuevo({ codigo: '', tipo: '' }); recargar(); })}>Agregar equipo</button>
      </div>}
      {puede(colegio, 'lab.equipos.prestar') && <div className="tarjeta"><div className="filtros">
        <Campo etiqueta="Prestar: código"><input value={p.codigo} onChange={(e) => setP({ ...p, codigo: e.target.value })} /></Campo>
        <Campo etiqueta="A"><select value={p.personal_id} onChange={(e) => setP({ ...p, personal_id: e.target.value })}><option value="">Docente…</option>{(base.datos?.personal ?? []).map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}</select></Campo>
        <Campo etiqueta="Hasta"><input type="datetime-local" value={p.hasta} onChange={(e) => setP({ ...p, hasta: e.target.value })} /></Campo>
        <button type="button" className="boton" disabled={!p.codigo || !p.personal_id || !p.hasta} onClick={() => accion.ejecutar(async () => { await pedir('/laboratorio/prestamos', { metodo: 'POST', cuerpo: { codigo: p.codigo, personal_id: Number(p.personal_id), hasta: new Date(p.hasta).toISOString() } }); setP({ codigo: '', personal_id: '', hasta: '' }); recargar(); })}>Prestar</button>
      </div><div className="filtros">
        <Campo etiqueta="Devolver: código"><input value={d.codigo} onChange={(e) => setD({ ...d, codigo: e.target.value })} /></Campo>
        <Campo etiqueta="Revisión"><select value={d.resultado} onChange={(e) => setD({ ...d, resultado: e.target.value })}><option value="bueno">Sin observaciones</option><option value="danado">Con daño (va a reparación)</option></select></Campo>
        <Campo etiqueta="Observación"><input value={d.observacion} onChange={(e) => setD({ ...d, observacion: e.target.value })} /></Campo>
        <button type="button" className="boton boton-borde" disabled={!d.codigo} onClick={() => accion.ejecutar(async () => { const r = await pedir<{ estado: string }>('/laboratorio/devoluciones', { metodo: 'POST', cuerpo: { ...d, observacion: d.observacion || null } }); setD({ codigo: '', resultado: 'bueno', observacion: '' }); recargar(); accion.setOk(r.estado === 'reparacion' ? 'Devuelto con daño: pasa a reparación y se abrió una incidencia.' : 'Devuelto.'); })}>Devolver</button>
      </div></div>}
      {datos && <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Código</th><th>Tipo</th><th>Estado</th><th>Prestado a</th></tr></thead><tbody>
        {datos.data.map((e) => <tr key={e.id}><td>{e.codigo}</td><td>{e.tipo}</td><td><span className={`insignia ${ESTADO_EQUIPO[e.estado]}`}>{e.estado}</span></td><td>{e.prestado_a ? <>{e.prestado_a} · hasta {fechaHora(e.hasta)}{e.vencido && <> <span className="insignia insignia-rojo">Vencido</span></>}</> : '—'}</td></tr>)}
      </tbody></table></div></div>}
    </>
  );
}

function Materiales() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Material[] }>('/laboratorio/materiales'));
  const [f, setF] = useState({ nombre: '', unidad: 'unidad', stock: '', stock_minimo: '', vence: '', ubicacion: '', peligroso: false, con_llave: false });
  const [hoja, setHoja] = useState<File | null>(null);
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {puede(colegio, 'lab.equipos.gestionar') && <div className="tarjeta"><h2>Nuevo material o reactivo</h2><div className="campos">
        <Campo etiqueta="Nombre"><input value={f.nombre} onChange={(e) => setF({ ...f, nombre: e.target.value })} /></Campo>
        <Campo etiqueta="Unidad"><input value={f.unidad} onChange={(e) => setF({ ...f, unidad: e.target.value })} /></Campo>
        <Campo etiqueta="Stock"><input type="number" value={f.stock} onChange={(e) => setF({ ...f, stock: e.target.value })} /></Campo>
        <Campo etiqueta="Stock mínimo"><input type="number" value={f.stock_minimo} onChange={(e) => setF({ ...f, stock_minimo: e.target.value })} /></Campo>
        <Campo etiqueta="Vence"><input type="date" value={f.vence} onChange={(e) => setF({ ...f, vence: e.target.value })} /></Campo>
        <Campo etiqueta="Ubicación"><input value={f.ubicacion} onChange={(e) => setF({ ...f, ubicacion: e.target.value })} /></Campo>
        <label className="campo"><span><input type="checkbox" checked={f.peligroso} onChange={(e) => setF({ ...f, peligroso: e.target.checked })} /> Reactivo peligroso</span></label>
        {f.peligroso && <><label className="campo"><span><input type="checkbox" checked={f.con_llave} onChange={(e) => setF({ ...f, con_llave: e.target.checked })} /> Guardado con llave</span></label><Campo etiqueta="Hoja de seguridad (PDF)"><input type="file" accept="application/pdf" onChange={(e) => setHoja(e.target.files?.[0] ?? null)} /></Campo></>}
      </div>
      <button type="button" className="boton" disabled={!f.nombre || !f.stock || accion.enviando} onClick={() => accion.ejecutar(async () => {
        const cuerpo = new FormData();
        Object.entries(f).forEach(([k, v]) => { if (typeof v === 'boolean') cuerpo.append(k, v ? '1' : '0'); else if (v) cuerpo.append(k, v); });
        if (hoja) cuerpo.append('hoja_seguridad', hoja);
        await pedir('/laboratorio/materiales', { metodo: 'POST', cuerpo });
        setF({ nombre: '', unidad: 'unidad', stock: '', stock_minimo: '', vence: '', ubicacion: '', peligroso: false, con_llave: false });
        setHoja(null);
        recargar();
      })}>Guardar</button></div>}
      {datos && <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Material</th><th>Stock</th><th>Vence</th><th>Ubicación</th><th /></tr></thead><tbody>
        {datos.data.map((m) => <tr key={m.id}>
          <td>{m.nombre}{m.peligroso && <> <span className="insignia insignia-rojo">Peligroso</span></>}</td>
          <td>{m.stock} {m.unidad}{m.bajo_minimo && <> <span className="insignia insignia-ambar">Bajo mínimo</span></>}</td>
          <td>{m.vence ? fecha(m.vence) : '—'}{m.vencido ? <> <span className="insignia insignia-rojo">Vencido</span></> : m.por_vencer ? <> <span className="insignia insignia-ambar">Por vencer</span></> : null}</td>
          <td>{m.ubicacion ?? '—'}</td>
          <td><div className="acciones">
            {m.tiene_hoja && <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => window.open((await pedir<{ url: string }>(`/laboratorio/materiales/${m.id}/hoja`)).url, '_blank', 'noopener'))}>Hoja de seguridad</button>}
            {puede(colegio, 'lab.equipos.prestar') && !m.vencido && <button type="button" className="boton boton-borde boton-chico" onClick={() => { const c = window.prompt(`¿Cuánto entregas (${m.unidad})?`); if (c && Number(c) > 0) void accion.ejecutar(async () => { await pedir(`/laboratorio/materiales/${m.id}/entrega`, { metodo: 'POST', cuerpo: { cantidad: Number(c) } }); recargar(); }); }}>Entregar</button>}
          </div></td>
        </tr>)}
      </tbody></table></div></div>}
    </>
  );
}

function Incidencias() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Incidencia[] }>('/laboratorio/incidencias'));
  const [f, setF] = useState({ detalle: '', equipo: '' });
  const accion = useAccion();
  const atender = (i: Incidencia, e: 'en_proceso' | 'cerrada') => {
    const respuesta = e === 'cerrada' ? window.prompt('¿Cómo se resolvió?') : null;
    if (e === 'cerrada' && !respuesta?.trim()) return;
    void accion.ejecutar(async () => { await pedir(`/laboratorio/incidencias/${i.id}/estado`, { metodo: 'POST', cuerpo: { estado: e, respuesta } }); recargar(); });
  };
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {puede(colegio, 'lab.reservas.reservar') && <div className="tarjeta"><div className="campos">
        <Campo etiqueta="¿Qué pasó?" ancho><textarea rows={2} value={f.detalle} onChange={(e) => setF({ ...f, detalle: e.target.value })} /></Campo>
        <Campo etiqueta="Código del equipo (si aplica)"><input value={f.equipo} onChange={(e) => setF({ ...f, equipo: e.target.value })} /></Campo>
      </div><button type="button" className="boton" disabled={f.detalle.trim().length < 5} onClick={() => accion.ejecutar(async () => { await pedir('/laboratorio/incidencias', { metodo: 'POST', cuerpo: { detalle: f.detalle, equipo: f.equipo || null } }); setF({ detalle: '', equipo: '' }); recargar(); })}>Reportar</button></div>}
      {datos && <div className="tarjeta"><ul className="lista-simple">{datos.data.map((i) => <li key={i.id}>
        <b>{fecha(i.fecha)}</b> · {i.detalle}{i.equipo ? ` (${i.equipo})` : ''} · <span className={`insignia ${i.estado === 'cerrada' ? 'insignia-ok' : i.estado === 'en_proceso' ? 'insignia-azul' : 'insignia-ambar'}`}>{i.estado.replace('_', ' ')}</span>
        <br /><small className="ayuda">{i.reportado_por}{i.respuesta ? ` · ${i.respuesta}` : ''}</small>
        {puede(colegio, 'lab.equipos.gestionar') && i.estado !== 'cerrada' && <> {i.estado === 'abierta' && <button type="button" className="enlace" onClick={() => atender(i, 'en_proceso')}>Atender</button>} <button type="button" className="enlace" onClick={() => atender(i, 'cerrada')}>Cerrar</button></>}
      </li>)}</ul></div>}
    </>
  );
}
