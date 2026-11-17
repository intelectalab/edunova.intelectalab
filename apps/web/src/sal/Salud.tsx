import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Pestanas, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Ficha = { grupo_sanguineo: string | null; seguro: string | null; alergias: string | null; condiciones: string | null; medicacion: string | null; contacto_emergencia: string | null; telefono_emergencia: string | null; updated_at?: string };
type FilaFicha = { alumno_id: number; alumno: string; tiene_ficha: boolean; alertas: boolean; actualizada_at: string | null };
type Atencion = { id: number; atendido_at: string; motivo: string; accion: string; derivado: boolean; retirado_por: string | null; padre_notificado_at: string | null; alumno: string; registrado_por: string };
type Campana = { id: number; nombre: string; fecha: string; descripcion: string | null; total: number; autorizados: number; no_autorizan: number; pendientes: number; secciones: string[] };

const VACIA: Ficha = { grupo_sanguineo: '', seguro: '', alergias: '', condiciones: '', medicacion: '', contacto_emergencia: '', telefono_emergencia: '' };

/** Formulario de la ficha de salud (enfermería y familia). */
export function FormFicha({ inicial, alGuardar, enviando }: { inicial: Ficha | null; alGuardar: (f: Ficha) => void; enviando: boolean }) {
  const [f, setF] = useState<Ficha>({ ...VACIA, ...(inicial ?? {}) });
  const campo = (k: keyof Ficha, etiqueta: string, largo = false) => (
    <Campo etiqueta={etiqueta} ancho={largo}>{largo ? <textarea rows={2} value={f[k] ?? ''} onChange={(e) => setF({ ...f, [k]: e.target.value })} /> : <input value={f[k] ?? ''} onChange={(e) => setF({ ...f, [k]: e.target.value })} />}</Campo>
  );
  return (
    <>
      <div className="campos">
        {campo('grupo_sanguineo', 'Grupo sanguíneo')}
        {campo('seguro', 'Seguro (SIS, EsSalud, EPS…)')}
        {campo('alergias', 'Alergias', true)}
        {campo('condiciones', 'Condiciones (asma, diabetes…)', true)}
        {campo('medicacion', 'Medicación', true)}
        {campo('contacto_emergencia', 'Contacto de emergencia')}
        {campo('telefono_emergencia', 'Teléfono de emergencia')}
      </div>
      <button type="button" className="boton" disabled={enviando} onClick={() => alGuardar(f)}>Guardar ficha</button>
    </>
  );
}

/** EDUN-177 / 178 · Enfermería: fichas de salud, atenciones del tópico y campañas. */
export function SaludEnfermeria() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [pestana, setPestana] = useState<'fichas' | 'atenciones' | 'campanas'>('fichas');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Servicios`} titulo="Enfermería" descripcion="Datos de salud confidenciales (Ley 29733): cada consulta queda registrada en la auditoría." />
      <Pestanas opciones={[['fichas', 'Fichas de salud'], ['atenciones', 'Atenciones'], ['campanas', 'Campañas']]} actual={pestana} alCambiar={setPestana} />
      {pestana === 'fichas' && <Fichas />}
      {pestana === 'atenciones' && <Atenciones />}
      {pestana === 'campanas' && <Campanas />}
    </>
  );
}

function Fichas() {
  const { pedir, estado } = useSesion();
  const edita = puede(estado!.colegio_activo!, 'sal.salud.registrar');
  const [buscar, setBuscar] = useState('');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: FilaFicha[] }>(`/salud/fichas${buscar.trim().length >= 3 ? `?buscar=${encodeURIComponent(buscar.trim())}` : ''}`), [buscar.trim().length >= 3 ? buscar : '']);
  const [abierta, setAbierta] = useState<{ alumno_id: number; alumno: string; ficha: Ficha | null } | null>(null);
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="filtros"><Campo etiqueta="Buscar alumno" ayuda="Sin buscar se muestran los alumnos con ficha."><input value={buscar} onChange={(e) => setBuscar(e.target.value)} placeholder="Apellido, nombre o DNI" /></Campo></div>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">Sin resultados.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Alumno</th><th>Ficha</th><th /></tr></thead><tbody>
          {datos.data.map((a) => (
            <tr key={a.alumno_id}>
              <td>{a.alumno}</td>
              <td>{a.tiene_ficha ? <>{a.alertas && <span className="insignia insignia-rojo">Alergias o condiciones</span>} <small className="ayuda">Actualizada {fechaHora(a.actualizada_at)}</small></> : <span className="insignia insignia-gris">Sin ficha</span>}</td>
              <td><button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => setAbierta(await pedir(`/salud/fichas/${a.alumno_id}`)))}>Abrir</button></td>
            </tr>
          ))}
        </tbody></table></div></div>
      )}
      {abierta && (
        <Dialogo titulo={`Ficha de salud: ${abierta.alumno}`} alCerrar={() => setAbierta(null)}>
          <Aviso tipo="ambar">Confidencial: esta consulta quedó registrada en la auditoría.</Aviso>
          {edita ? <FormFicha inicial={abierta.ficha} enviando={accion.enviando} alGuardar={(f) => accion.ejecutar(async () => { await pedir(`/salud/fichas/${abierta.alumno_id}`, { metodo: 'PUT', cuerpo: f }); setAbierta(null); recargar(); accion.setOk('Ficha guardada.'); })} />
            : <pre style={{ whiteSpace: 'pre-wrap' }}>{JSON.stringify(abierta.ficha, null, 2)}</pre>}
        </Dialogo>
      )}
    </>
  );
}

function Atenciones() {
  const { pedir } = useSesion();
  const [dia, setDia] = useState(() => new Date().toLocaleDateString('sv-SE'));
  const { datos, error } = useCarga(() => pedir<{ data: Atencion[] }>(`/salud/atenciones?fecha=${dia}`), [dia]);
  return (
    <>
      <Aviso tipo="error">{error}</Aviso>
      <div className="filtros"><Campo etiqueta="Día"><input type="date" value={dia} onChange={(e) => setDia(e.target.value)} /></Campo></div>
      <p className="ayuda">Las atenciones se registran desde la app de enfermería.</p>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hubo atenciones ese día.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Hora</th><th>Alumno</th><th>Motivo y acción</th><th>Familia</th></tr></thead><tbody>
          {datos.data.map((a) => (
            <tr key={a.id}>
              <td>{fechaHora(a.atendido_at)}</td>
              <td>{a.alumno}</td>
              <td>{a.motivo}<br /><small className="ayuda">{a.accion}{a.derivado ? ` · se retiró con ${a.retirado_por ?? '—'}` : ''}</small></td>
              <td>{a.padre_notificado_at ? `Avisada ${fechaHora(a.padre_notificado_at)}` : '—'}</td>
            </tr>
          ))}
        </tbody></table></div></div>
      )}
    </>
  );
}

function Campanas() {
  const { pedir, estado } = useSesion();
  const edita = puede(estado!.colegio_activo!, 'sal.salud.registrar');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Campana[]; secciones: { id: number; nombre: string }[] }>('/salud/campanas'));
  const [nueva, setNueva] = useState<{ nombre: string; fecha: string; descripcion: string; secciones: number[] } | null>(null);
  const [lista, setLista] = useState<{ c: Campana; filas: { alumno_id: number; alumno: string; seccion: string; estado: string }[] } | null>(null);
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {edita && <div className="acciones"><button type="button" className="boton" onClick={() => setNueva({ nombre: '', fecha: '', descripcion: '', secciones: [] })}>Nueva campaña</button></div>}
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">Sin campañas.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Campaña</th><th>Fecha</th><th>Secciones</th><th>Autorizaciones</th><th /></tr></thead><tbody>
          {datos.data.map((c) => (
            <tr key={c.id}>
              <td>{c.nombre}</td><td>{fecha(c.fecha)}</td><td>{c.secciones.join(', ')}</td>
              <td><span className="insignia insignia-ok">{c.autorizados} sí</span> <span className="insignia insignia-rojo">{c.no_autorizan} no</span> <span className="insignia insignia-ambar">{c.pendientes} pendientes</span></td>
              <td><button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => setLista({ c, filas: (await pedir<{ data: { alumno_id: number; alumno: string; seccion: string; estado: string }[] }>(`/salud/campanas/${c.id}/autorizados`)).data }))}>Lista del día</button></td>
            </tr>
          ))}
        </tbody></table></div></div>
      )}
      {nueva && datos && (
        <Dialogo titulo="Nueva campaña de salud" alCerrar={() => setNueva(null)}>
          <Aviso tipo="error">{accion.error}</Aviso>
          <div className="campos">
            <Campo etiqueta="Nombre"><input value={nueva.nombre} onChange={(e) => setNueva({ ...nueva, nombre: e.target.value })} placeholder="Vacunación, despistaje de anemia…" /></Campo>
            <Campo etiqueta="Fecha"><input type="date" value={nueva.fecha} onChange={(e) => setNueva({ ...nueva, fecha: e.target.value })} /></Campo>
            <Campo etiqueta="Texto de la autorización para las familias" ancho><textarea rows={4} value={nueva.descripcion} onChange={(e) => setNueva({ ...nueva, descripcion: e.target.value })} /></Campo>
          </div>
          <div className="casillas">{datos.secciones.map((s) => <label key={s.id} className="casilla"><input type="checkbox" checked={nueva.secciones.includes(s.id)} onChange={(e) => setNueva({ ...nueva, secciones: e.target.checked ? [...nueva.secciones, s.id] : nueva.secciones.filter((x) => x !== s.id) })} /> {s.nombre}</label>)}</div>
          <button type="button" className="boton" disabled={accion.enviando || !nueva.fecha || nueva.nombre.trim().length < 3 || nueva.descripcion.trim().length < 10 || !nueva.secciones.length} onClick={() => accion.ejecutar(async () => { await pedir('/salud/campanas', { metodo: 'POST', cuerpo: nueva }); setNueva(null); recargar(); accion.setOk('Campaña creada. Las familias recibieron la autorización en la app.'); })}>Crear y pedir autorización</button>
        </Dialogo>
      )}
      {lista && (
        <Dialogo titulo={`${lista.c.nombre} · ${fecha(lista.c.fecha)}`} alCerrar={() => setLista(null)}>
          <div className="tabla-caja"><table className="tabla"><thead><tr><th>Alumno</th><th>Sección</th><th>Autorización</th></tr></thead><tbody>
            {lista.filas.map((f) => <tr key={f.alumno_id}><td>{f.alumno}</td><td>{f.seccion}</td><td><span className={`insignia ${f.estado === 'autorizado' ? 'insignia-ok' : f.estado === 'no_autoriza' ? 'insignia-rojo' : 'insignia-ambar'}`}>{f.estado === 'autorizado' ? 'Autorizado' : f.estado === 'no_autoriza' ? 'No autoriza' : 'Pendiente'}</span></td></tr>)}
          </tbody></table></div>
          <button type="button" className="boton boton-borde" onClick={() => window.print()}>Imprimir</button>
        </Dialogo>
      )}
    </>
  );
}

/** EDUN-177 · La familia mantiene la ficha de salud de sus hijos. */
export function SaludFamilia() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const familia = useCarga(() => pedir<{ hijos: { id: number; nombres: string; apellido_paterno: string }[] }>('/me/familia'));
  const [hijo, setHijo] = useState<number | null>(null);
  const id = hijo ?? familia.datos?.hijos[0]?.id ?? null;
  const ficha = useCarga(() => (id ? pedir<{ ficha: Ficha | null }>(`/familia/salud/${id}`) : Promise.resolve({ ficha: null })), [id]);
  const accion = useAccion();
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Ficha de salud" descripcion="Mantén al día alergias, condiciones, medicación y el contacto de emergencia. Solo la ve enfermería." />
      <Aviso tipo="error">{familia.error ?? ficha.error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {(familia.datos?.hijos.length ?? 0) > 1 && <div className="acciones">{familia.datos!.hijos.map((h) => <button key={h.id} type="button" className={`boton boton-chico ${h.id === id ? '' : 'boton-borde'}`} onClick={() => setHijo(h.id)}>{h.nombres}</button>)}</div>}
      {ficha.datos && id && <div className="tarjeta" key={id}><FormFicha inicial={ficha.datos.ficha} enviando={accion.enviando} alGuardar={(f) => accion.ejecutar(async () => { await pedir(`/familia/salud/${id}`, { metodo: 'PUT', cuerpo: f }); ficha.recargar(); accion.setOk('Ficha actualizada.'); })} /></div>}
    </>
  );
}
