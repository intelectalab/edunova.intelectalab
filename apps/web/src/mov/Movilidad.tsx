import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Pestanas, Titulo } from '../panel/ui';
import { MonitorRecorridos } from './Seguimiento';
import { fecha, useAccion, useCarga } from '../panel/utilidades';

type Unidad = { id: number; placa: string; capacidad: number; soat_vence: string | null; autorizacion_vence: string | null; revision_tecnica_vence: string | null; propia: boolean; empresa: string | null; por_vencer: string[] };
type Persona = { id: number; nombres: string; numero_documento: string; rol: 'conductor' | 'auxiliar'; licencia: string | null; licencia_vence: string | null; user_id: number | null; email: string | null };
type Paradero = { id?: number | null; nombre: string; hora_estimada: string | null; latitud?: number | null; longitud?: number | null };
type Ruta = { id: number; nombre: string; turno: string; mov_unidad_id: number | null; conductor_id: number | null; auxiliar_id: number | null; placa: string | null; capacidad: number | null; paraderos: Paradero[]; alumnos: number };
type Resumen = { unidades: Unidad[]; personal: Persona[]; rutas: Ruta[]; usuarios: { id: number; nombre: string; email: string | null }[]; turnos: string[] };
type Asignado = { alumno_id: number; mov_paradero_id: number; alumno: string; paradero: string };

const num = (v: string) => (v === '' ? null : Number(v));
const hora = (h: string | null) => (h ? h.slice(0, 5) : null);
const TURNO: Record<string, string> = { manana: 'Mañana', tarde: 'Tarde' };

/** EDUN-161 / EDUN-162 · Movilidad escolar: rutas con paraderos y alumnos, unidades y personal. */
export function Movilidad() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const gestiona = puede(colegio, 'mov.movilidad.gestionar');
  const [pestana, setPestana] = useState<'rutas' | 'unidades' | 'personal' | 'monitor'>('rutas');
  const { datos, error, recargar } = useCarga(() => pedir<Resumen>('/movilidad/configuracion'));
  const accion = useAccion();
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Servicios`} titulo="Movilidad escolar" descripcion="Rutas por turno con sus paraderos y alumnos, unidades y personal. El conductor y el auxiliar usan la app para iniciar el recorrido y marcar quién sube y baja." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <Pestanas opciones={[['rutas', 'Rutas'], ['unidades', 'Unidades'], ['personal', 'Personal'], ['monitor', 'Monitor de hoy']]} actual={pestana} alCambiar={setPestana} />
      {pestana === 'monitor' ? <MonitorRecorridos /> : !datos ? <p className="aviso-carga" role="status">Cargando…</p> : pestana === 'rutas' ? (
        <Rutas datos={datos} gestiona={gestiona} recargar={recargar} accion={accion} />
      ) : pestana === 'unidades' ? (
        <Unidades datos={datos} gestiona={gestiona} recargar={recargar} accion={accion} />
      ) : (
        <PersonalMovilidad datos={datos} gestiona={gestiona} recargar={recargar} accion={accion} />
      )}
    </>
  );
}

type Props = { datos: Resumen; gestiona: boolean; recargar: () => void; accion: ReturnType<typeof useAccion> };

function Rutas({ datos, gestiona, recargar, accion }: Props) {
  const { pedir } = useSesion();
  const [editando, setEditando] = useState<Partial<Ruta> | null>(null);
  const [paraderos, setParaderos] = useState<Ruta | null>(null);
  const [alumnos, setAlumnos] = useState<Ruta | null>(null);
  const persona = (id: number | null) => datos.personal.find((p) => p.id === id)?.nombres ?? '—';
  const guardar = () => accion.ejecutar(async () => {
    const r = editando!;
    await pedir(r.id ? `/movilidad/rutas/${r.id}` : '/movilidad/rutas', { metodo: r.id ? 'PUT' : 'POST', cuerpo: { nombre: r.nombre, turno: r.turno, mov_unidad_id: r.mov_unidad_id ?? null, conductor_id: r.conductor_id ?? null, auxiliar_id: r.auxiliar_id ?? null } });
    setEditando(null);
    recargar();
  }, 'Ruta guardada.');
  return (
    <>
      {gestiona && <div className="acciones"><button type="button" className="boton" onClick={() => setEditando({ nombre: '', turno: datos.turnos[0] })}>Nueva ruta</button></div>}
      {datos.rutas.length === 0 ? <p className="vacio">Todavía no hay rutas. Registra primero las unidades y el personal.</p> : (
        <div className="tarjeta"><div className="tabla-caja">
          <table className="tabla"><thead><tr><th>Ruta</th><th>Turno</th><th>Unidad</th><th>Conductor · Auxiliar</th><th>Paraderos</th><th>Alumnos</th>{gestiona && <th>Acciones</th>}</tr></thead><tbody>
            {datos.rutas.map((r) => (
              <tr key={r.id}>
                <td>{r.nombre}</td><td>{TURNO[r.turno] ?? r.turno}</td><td>{r.placa ?? '—'}</td>
                <td>{persona(r.conductor_id)} · {persona(r.auxiliar_id)}</td>
                <td>{r.paraderos.length}</td>
                <td>{r.alumnos}{r.capacidad ? ` de ${r.capacidad}` : ''}</td>
                {gestiona && <td><div className="acciones">
                  <button type="button" className="boton boton-chico boton-borde" onClick={() => setEditando(r)}>Editar</button>
                  <button type="button" className="boton boton-chico boton-borde" onClick={() => setParaderos(r)}>Paraderos</button>
                  <button type="button" className="boton boton-chico boton-borde" disabled={!r.paraderos.length} title={r.paraderos.length ? undefined : 'Primero agrega paraderos'} onClick={() => setAlumnos(r)}>Alumnos</button>
                </div></td>}
              </tr>
            ))}
          </tbody></table>
        </div></div>
      )}
      {editando && (
        <Dialogo titulo={editando.id ? 'Editar ruta' : 'Nueva ruta'} alCerrar={() => setEditando(null)}>
          <div className="campos">
            <Campo etiqueta="Nombre"><input value={editando.nombre ?? ''} onChange={(e) => setEditando({ ...editando, nombre: e.target.value })} /></Campo>
            <Campo etiqueta="Turno"><select value={editando.turno} onChange={(e) => setEditando({ ...editando, turno: e.target.value })}>{datos.turnos.map((t) => <option key={t} value={t}>{TURNO[t] ?? t}</option>)}</select></Campo>
            <Campo etiqueta="Unidad"><select value={editando.mov_unidad_id ?? ''} onChange={(e) => setEditando({ ...editando, mov_unidad_id: num(e.target.value) })}><option value="">Sin unidad</option>{datos.unidades.map((u) => <option key={u.id} value={u.id}>{u.placa} ({u.capacidad} asientos)</option>)}</select></Campo>
            <Campo etiqueta="Conductor"><select value={editando.conductor_id ?? ''} onChange={(e) => setEditando({ ...editando, conductor_id: num(e.target.value) })}><option value="">Sin asignar</option>{datos.personal.filter((p) => p.rol === 'conductor').map((p) => <option key={p.id} value={p.id}>{p.nombres}</option>)}</select></Campo>
            <Campo etiqueta="Auxiliar"><select value={editando.auxiliar_id ?? ''} onChange={(e) => setEditando({ ...editando, auxiliar_id: num(e.target.value) })}><option value="">Sin asignar</option>{datos.personal.filter((p) => p.rol === 'auxiliar').map((p) => <option key={p.id} value={p.id}>{p.nombres}</option>)}</select></Campo>
          </div>
          <div className="acciones"><button type="button" className="boton" disabled={accion.enviando || !editando.nombre?.trim()} onClick={guardar}>Guardar</button></div>
        </Dialogo>
      )}
      {paraderos && <EditorParaderos ruta={paraderos} alCerrar={() => setParaderos(null)} alGuardar={() => { setParaderos(null); recargar(); }} />}
      {alumnos && <EditorAlumnos ruta={alumnos} alCerrar={() => setAlumnos(null)} alGuardar={() => { setAlumnos(null); recargar(); }} />}
    </>
  );
}

function EditorParaderos({ ruta, alCerrar, alGuardar }: { ruta: Ruta; alCerrar: () => void; alGuardar: () => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [lista, setLista] = useState<Paradero[]>(ruta.paraderos.map((p) => ({ ...p, hora_estimada: hora(p.hora_estimada) })));
  const cambiar = (i: number, p: Partial<Paradero>) => setLista(lista.map((x, j) => (j === i ? { ...x, ...p } : x)));
  const mover = (i: number, d: -1 | 1) => {
    const n = [...lista];
    [n[i], n[i + d]] = [n[i + d], n[i]];
    setLista(n);
  };
  return (
    <Dialogo titulo={`Paraderos · ${ruta.nombre}`} alCerrar={alCerrar}>
      <p className="ayuda">En el orden en que los recorre la movilidad. La hora es la estimada de paso.</p>
      <Aviso tipo="error">{accion.error}</Aviso>
      <ol className="lista-paraderos">
        {lista.map((p, i) => (
          <li key={p.id ?? `n${i}`} className="campos">
            <Campo etiqueta={`Paradero ${i + 1}`}><input value={p.nombre} onChange={(e) => cambiar(i, { nombre: e.target.value })} /></Campo>
            <Campo etiqueta="Hora"><input type="time" value={p.hora_estimada ?? ''} onChange={(e) => cambiar(i, { hora_estimada: e.target.value || null })} /></Campo>
            <div className="acciones">
              <button type="button" className="boton boton-chico boton-borde" aria-label={`Subir paradero ${i + 1}`} disabled={i === 0} onClick={() => mover(i, -1)}>↑</button>
              <button type="button" className="boton boton-chico boton-borde" aria-label={`Bajar paradero ${i + 1}`} disabled={i === lista.length - 1} onClick={() => mover(i, 1)}>↓</button>
              <button type="button" className="boton boton-chico boton-borde" aria-label={`Quitar paradero ${i + 1}`} onClick={() => setLista(lista.filter((_, j) => j !== i))}>Quitar</button>
            </div>
          </li>
        ))}
      </ol>
      <div className="acciones">
        <button type="button" className="boton boton-borde" onClick={() => setLista([...lista, { nombre: '', hora_estimada: null }])}>Agregar paradero</button>
        <button type="button" className="boton" disabled={accion.enviando || lista.some((p) => !p.nombre.trim())} onClick={() => accion.ejecutar(async () => {
          await pedir(`/movilidad/rutas/${ruta.id}/paraderos`, { metodo: 'PUT', cuerpo: { paraderos: lista.map((p) => ({ id: p.id ?? null, nombre: p.nombre.trim(), hora_estimada: p.hora_estimada || null })) } });
          alGuardar();
        })}>Guardar paraderos</button>
      </div>
    </Dialogo>
  );
}

function EditorAlumnos({ ruta, alCerrar, alGuardar }: { ruta: Ruta; alCerrar: () => void; alGuardar: () => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const actuales = useCarga(() => pedir<{ data: Asignado[] }>(`/movilidad/rutas/${ruta.id}/alumnos`), [ruta.id]);
  const [lista, setLista] = useState<Asignado[] | null>(null);
  const [q, setQ] = useState('');
  const [resultados, setResultados] = useState<{ id: number; alumno: string; seccion: string }[]>([]);
  const vista = lista ?? actuales.datos?.data ?? [];
  const primero = ruta.paraderos[0];
  return (
    <Dialogo titulo={`Alumnos · ${ruta.nombre}`} alCerrar={alCerrar}>
      <Aviso tipo="error">{actuales.error ?? accion.error}</Aviso>
      <p className="ayuda">{vista.length}{ruta.capacidad ? ` de ${ruta.capacidad} asientos` : ' alumnos'}. Quien sale de la ruta conserva su historial de subidas y bajadas.</p>
      <div className="filtros">
        <Campo etiqueta="Agregar alumno (apellido, nombre o DNI)"><input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && q.trim().length >= 3 && accion.ejecutar(async () => setResultados((await pedir<{ data: typeof resultados }>(`/movilidad/alumnos?q=${encodeURIComponent(q.trim())}`)).data))} /></Campo>
        <button type="button" className="boton boton-borde" disabled={q.trim().length < 3} onClick={() => accion.ejecutar(async () => setResultados((await pedir<{ data: typeof resultados }>(`/movilidad/alumnos?q=${encodeURIComponent(q.trim())}`)).data))}>Buscar</button>
      </div>
      {resultados.length > 0 && (
        <ul className="lista-simple">
          {resultados.filter((r) => !vista.some((v) => v.alumno_id === r.id)).map((r) => (
            <li key={r.id}>{r.alumno} <small className="ayuda">{r.seccion}</small> <button type="button" className="boton boton-chico" onClick={() => { setLista([...vista, { alumno_id: r.id, mov_paradero_id: primero.id!, alumno: r.alumno, paradero: primero.nombre }]); }}>Agregar</button></li>
          ))}
        </ul>
      )}
      <div className="tabla-caja"><table className="tabla"><thead><tr><th>Alumno</th><th>Paradero</th><th /></tr></thead><tbody>
        {vista.map((a) => (
          <tr key={a.alumno_id}>
            <td>{a.alumno}</td>
            <td><select aria-label={`Paradero de ${a.alumno}`} value={a.mov_paradero_id} onChange={(e) => setLista(vista.map((x) => (x.alumno_id === a.alumno_id ? { ...x, mov_paradero_id: Number(e.target.value) } : x)))}>
              {ruta.paraderos.map((p) => <option key={p.id!} value={p.id!}>{p.nombre}</option>)}
            </select></td>
            <td><button type="button" className="boton boton-chico boton-borde" onClick={() => setLista(vista.filter((x) => x.alumno_id !== a.alumno_id))}>Quitar</button></td>
          </tr>
        ))}
      </tbody></table></div>
      <div className="acciones"><button type="button" className="boton" disabled={accion.enviando || lista === null} onClick={() => accion.ejecutar(async () => {
        await pedir(`/movilidad/rutas/${ruta.id}/alumnos`, { metodo: 'PUT', cuerpo: { asignaciones: vista.map((a) => ({ alumno_id: a.alumno_id, paradero_id: a.mov_paradero_id })) } });
        alGuardar();
      })}>Guardar alumnos</button></div>
    </Dialogo>
  );
}

function Unidades({ datos, gestiona, recargar, accion }: Props) {
  const { pedir } = useSesion();
  const [u, setU] = useState<Partial<Unidad> | null>(null);
  return (
    <>
      {gestiona && <div className="acciones"><button type="button" className="boton" onClick={() => setU({ placa: '', capacidad: 15, propia: false })}>Nueva unidad</button></div>}
      {datos.unidades.length === 0 ? <p className="vacio">No hay unidades registradas.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Placa</th><th>Asientos</th><th>SOAT</th><th>Autorización</th><th>Revisión técnica</th><th>Avisos</th>{gestiona && <th>Acciones</th>}</tr></thead><tbody>
          {datos.unidades.map((x) => (
            <tr key={x.id}>
              <td>{x.placa}<br /><small className="ayuda">{x.propia ? 'Propia' : x.empresa ?? 'Tercerizada'}</small></td><td>{x.capacidad}</td>
              <td>{fecha(x.soat_vence)}</td><td>{fecha(x.autorizacion_vence)}</td><td>{fecha(x.revision_tecnica_vence)}</td>
              <td>{x.por_vencer.map((t) => <span key={t} className={`insignia ${t.includes('vencido') ? 'insignia-rojo' : 'insignia-ambar'}`}>{t}</span>)}</td>
              {gestiona && <td><div className="acciones">
                <button type="button" className="boton boton-chico boton-borde" onClick={() => setU(x)}>Editar</button>
                <button type="button" className="boton boton-chico boton-borde" disabled={accion.enviando} onClick={() => window.confirm(`¿Eliminar la unidad ${x.placa}?`) && accion.ejecutar(async () => { await pedir(`/movilidad/unidades/${x.id}`, { metodo: 'DELETE' }); recargar(); }, 'Unidad eliminada.')}>Eliminar</button>
              </div></td>}
            </tr>
          ))}
        </tbody></table></div></div>
      )}
      {u && (
        <Dialogo titulo={u.id ? 'Editar unidad' : 'Nueva unidad'} alCerrar={() => setU(null)}>
          <div className="campos">
            <Campo etiqueta="Placa"><input value={u.placa ?? ''} onChange={(e) => setU({ ...u, placa: e.target.value })} /></Campo>
            <Campo etiqueta="Asientos para alumnos"><input type="number" min={1} max={80} value={u.capacidad ?? ''} onChange={(e) => setU({ ...u, capacidad: Number(e.target.value) })} /></Campo>
            <Campo etiqueta="SOAT vence"><input type="date" value={u.soat_vence ?? ''} onChange={(e) => setU({ ...u, soat_vence: e.target.value || null })} /></Campo>
            <Campo etiqueta="Autorización vence"><input type="date" value={u.autorizacion_vence ?? ''} onChange={(e) => setU({ ...u, autorizacion_vence: e.target.value || null })} /></Campo>
            <Campo etiqueta="Revisión técnica vence"><input type="date" value={u.revision_tecnica_vence ?? ''} onChange={(e) => setU({ ...u, revision_tecnica_vence: e.target.value || null })} /></Campo>
            <Campo etiqueta="Empresa (si es tercerizada)"><input value={u.empresa ?? ''} disabled={u.propia} onChange={(e) => setU({ ...u, empresa: e.target.value || null })} /></Campo>
            <label className="casilla"><input type="checkbox" checked={!!u.propia} onChange={(e) => setU({ ...u, propia: e.target.checked, empresa: e.target.checked ? null : u.empresa })} /> Unidad propia del colegio</label>
          </div>
          <div className="acciones"><button type="button" className="boton" disabled={accion.enviando || !u.placa?.trim()} onClick={() => accion.ejecutar(async () => {
            await pedir(u.id ? `/movilidad/unidades/${u.id}` : '/movilidad/unidades', { metodo: u.id ? 'PUT' : 'POST', cuerpo: { placa: u.placa, capacidad: u.capacidad, soat_vence: u.soat_vence ?? null, autorizacion_vence: u.autorizacion_vence ?? null, revision_tecnica_vence: u.revision_tecnica_vence ?? null, propia: !!u.propia, empresa: u.empresa ?? null } });
            setU(null);
            recargar();
          }, 'Unidad guardada.')}>Guardar</button></div>
        </Dialogo>
      )}
    </>
  );
}

function PersonalMovilidad({ datos, gestiona, recargar, accion }: Props) {
  const { pedir } = useSesion();
  const [p, setP] = useState<Partial<Persona> | null>(null);
  return (
    <>
      {gestiona && <div className="acciones"><button type="button" className="boton" onClick={() => setP({ nombres: '', numero_documento: '', rol: 'conductor' })}>Agregar persona</button></div>}
      {datos.personal.length === 0 ? <p className="vacio">No hay conductores ni auxiliares registrados.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Nombre</th><th>Rol</th><th>Documento</th><th>Licencia</th><th>Usuario de la app</th>{gestiona && <th>Acciones</th>}</tr></thead><tbody>
          {datos.personal.map((x) => (
            <tr key={x.id}>
              <td>{x.nombres}</td><td>{x.rol === 'conductor' ? 'Conductor' : 'Auxiliar'}</td><td>{x.numero_documento}</td>
              <td>{x.licencia ? `${x.licencia}${x.licencia_vence ? ` · vence ${fecha(x.licencia_vence)}` : ''}` : '—'}</td>
              <td>{x.email ?? <span className="insignia insignia-ambar">Sin usuario: no puede usar la app</span>}</td>
              {gestiona && <td><button type="button" className="boton boton-chico boton-borde" onClick={() => setP(x)}>Editar</button></td>}
            </tr>
          ))}
        </tbody></table></div></div>
      )}
      {p && (
        <Dialogo titulo={p.id ? 'Editar persona' : 'Agregar conductor o auxiliar'} alCerrar={() => setP(null)}>
          <div className="campos">
            <Campo etiqueta="Nombres y apellidos"><input value={p.nombres ?? ''} onChange={(e) => setP({ ...p, nombres: e.target.value })} /></Campo>
            <Campo etiqueta="Documento"><input value={p.numero_documento ?? ''} onChange={(e) => setP({ ...p, numero_documento: e.target.value })} /></Campo>
            <Campo etiqueta="Rol"><select value={p.rol} onChange={(e) => setP({ ...p, rol: e.target.value as Persona['rol'] })}><option value="conductor">Conductor</option><option value="auxiliar">Auxiliar</option></select></Campo>
            {p.rol === 'conductor' && <>
              <Campo etiqueta="Licencia"><input value={p.licencia ?? ''} onChange={(e) => setP({ ...p, licencia: e.target.value || null })} /></Campo>
              <Campo etiqueta="Licencia vence"><input type="date" value={p.licencia_vence ?? ''} onChange={(e) => setP({ ...p, licencia_vence: e.target.value || null })} /></Campo>
            </>}
            <Campo etiqueta="Usuario de la app" ayuda="Para que use la app. Invítalo antes en Usuarios con el rol «Conductor» o «Movilidad»."><select value={p.user_id ?? ''} onChange={(e) => setP({ ...p, user_id: num(e.target.value) })}><option value="">Sin usuario</option>{datos.usuarios.map((u) => <option key={u.id} value={u.id}>{u.nombre}{u.email ? ` · ${u.email}` : ''}</option>)}</select></Campo>
          </div>
          <div className="acciones"><button type="button" className="boton" disabled={accion.enviando || !p.nombres?.trim() || !p.numero_documento?.trim()} onClick={() => accion.ejecutar(async () => {
            await pedir(p.id ? `/movilidad/personal/${p.id}` : '/movilidad/personal', { metodo: p.id ? 'PUT' : 'POST', cuerpo: { nombres: p.nombres, numero_documento: p.numero_documento, rol: p.rol, licencia: p.rol === 'conductor' ? p.licencia ?? null : null, licencia_vence: p.rol === 'conductor' ? p.licencia_vence ?? null : null, user_id: p.user_id ?? null } });
            setP(null);
            recargar();
          }, 'Datos guardados.')}>Guardar</button></div>
        </Dialogo>
      )}
    </>
  );
}

type Salida = { id: number; hora: string | null; origen: string; motivo: string; alumno: string; recoge: string };
const ORIGEN: Record<string, string> = { porteria: 'Portería', topico: 'Tópico', app: 'App', secretaria: 'Secretaría' };

/** EDUN-214 · Salidas verificadas del día (portería y tópico). */
export function SalidasDelDia() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [dia, setDia] = useState(() => new Date().toLocaleDateString('sv-SE'));
  const { datos, error } = useCarga(() => pedir<{ data: Salida[] }>(`/porteria/salidas?fecha=${dia}`), [dia]);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Servicios`} titulo="Salidas del día" descripcion="Cada salida se verifica con el documento de quien recoge (apoderado o persona autorizada en la ficha del alumno). La familia recibe el aviso." />
      <Aviso tipo="error">{error}</Aviso>
      <div className="filtros"><Campo etiqueta="Día"><input type="date" value={dia} onChange={(e) => setDia(e.target.value)} /></Campo></div>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hubo salidas ese día.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Hora</th><th>Alumno</th><th>Recogió</th><th>Dónde</th><th>Motivo</th></tr></thead><tbody>
          {datos.data.map((s) => <tr key={s.id}><td>{hora(s.hora)}</td><td>{s.alumno}</td><td>{s.recoge}</td><td>{ORIGEN[s.origen] ?? s.origen}</td><td>{s.motivo}</td></tr>)}
        </tbody></table></div></div>
      )}
    </>
  );
}
