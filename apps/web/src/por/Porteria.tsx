import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Anticipada = { id: number; alumno_id: number; alumno: string; seccion: string | null; fecha: string; hora: string; motivo: string; origen: string; estado: 'solicitada' | 'autorizada' | 'salio' | 'rechazada' | 'anulada'; respuesta: string | null; recoge: string; salio_at: string | null };
type Visita = { id: number; nombres: string; numero_documento: string; visita_a: string; motivo: string | null; ingreso_at: string; salida_at: string | null; registrado_por: string };
type Autorizado = { id: number; alumno_id: number; alumno: string; nombres: string; numero_documento: string; parentesco: string; vigente_hasta: string | null; activo: boolean; vigente: boolean };

const ESTADO: Record<Anticipada['estado'], { texto: string; clase: string }> = {
  solicitada: { texto: 'Por autorizar', clase: 'insignia-ambar' },
  autorizada: { texto: 'Autorizada', clase: 'insignia-azul' },
  salio: { texto: 'Salió', clase: 'insignia-ok' },
  rechazada: { texto: 'No autorizada', clase: 'insignia-rojo' },
  anulada: { texto: 'Anulada', clase: 'insignia-gris' },
};
const hoy = () => new Date().toLocaleDateString('sv-SE');

/** EDUN-189 · Salidas anticipadas: las pide la familia (o secretaría) y se autorizan aquí. */
export function SalidasAnticipadas() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const autoriza = puede(colegio, 'por.salidas.autorizar');
  const [filtro, setFiltro] = useState('');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Anticipada[] }>(`/porteria/anticipadas${filtro ? `?estado=${filtro}` : ''}`), [filtro]);
  const accion = useAccion();
  const resolver = (s: Anticipada, decision: 'autorizar' | 'rechazar') => {
    const respuesta = decision === 'rechazar' ? window.prompt('Explica a la familia por qué no se autoriza:') : null;
    if (decision === 'rechazar' && !respuesta?.trim()) return;
    void accion.ejecutar(async () => {
      await pedir(`/porteria/anticipadas/${s.id}/decision`, { metodo: 'POST', cuerpo: { decision, respuesta } });
      recargar();
      accion.setOk(decision === 'autorizar' ? `Autorizada: portería entregará a ${s.alumno} a ${s.recoge}.` : 'No autorizada. La familia recibió tu respuesta.');
    });
  };
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Servicios`} titulo="Salidas anticipadas" descripcion="La familia las pide desde la app con la hora, el motivo y quién recoge. Al autorizarlas, portería ve la salida en su lista y la verifica con el DNI." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="filtros"><Campo etiqueta="Mostrar"><select value={filtro} onChange={(e) => setFiltro(e.target.value)}><option value="">Última semana y próximas</option>{Object.entries(ESTADO).map(([v, e]) => <option key={v} value={v}>{e.texto}</option>)}</select></Campo></div>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hay salidas anticipadas.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Día y hora</th><th>Alumno</th><th>Recoge</th><th>Motivo</th><th>Estado</th><th /></tr></thead><tbody>
          {datos.data.map((s) => (
            <tr key={s.id}>
              <td>{fecha(s.fecha)} · {s.hora.slice(0, 5)}</td>
              <td>{s.alumno}<br /><small className="ayuda">{s.seccion}</small></td>
              <td>{s.recoge}</td>
              <td>{s.motivo}{s.respuesta && <><br /><small className="ayuda">{s.respuesta}</small></>}</td>
              <td><span className={`insignia ${ESTADO[s.estado].clase}`}>{ESTADO[s.estado].texto}</span>{s.salio_at && <><br /><small className="ayuda">{fechaHora(s.salio_at)}</small></>}</td>
              <td>{autoriza && <div className="acciones">
                {s.estado === 'solicitada' && <>
                  <button type="button" className="boton boton-chico" disabled={accion.enviando} onClick={() => resolver(s, 'autorizar')}>Autorizar</button>
                  <button type="button" className="boton boton-borde boton-chico" disabled={accion.enviando} onClick={() => resolver(s, 'rechazar')}>No autorizar</button>
                </>}
                {['solicitada', 'autorizada'].includes(s.estado) && <button type="button" className="boton boton-borde boton-chico" disabled={accion.enviando} onClick={() => window.confirm('¿Anular esta salida?') && accion.ejecutar(async () => { await pedir(`/porteria/anticipadas/${s.id}/anulacion`, { metodo: 'POST' }); recargar(); })}>Anular</button>}
              </div>}</td>
            </tr>
          ))}
        </tbody></table></div></div>
      )}
    </>
  );
}

/** EDUN-190 · Visitas: registro en la puerta con hora de ingreso y de salida. */
export function Visitas() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const registra = puede(colegio, 'por.visitas.registrar');
  const [dia, setDia] = useState(hoy);
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Visita[] }>(`/porteria/visitas?fecha=${dia}`), [dia]);
  const [f, setF] = useState({ nombres: '', numero_documento: '', visita_a: '', motivo: '' });
  const accion = useAccion();
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Servicios`} titulo="Visitas" descripcion="Registra a cada visitante con su documento, a quién visita y el motivo. Marca su salida al retirarse." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {registra && (
        <form className="tarjeta" onSubmit={(e) => {
          e.preventDefault();
          void accion.ejecutar(async () => {
            await pedir('/porteria/visitas', { metodo: 'POST', cuerpo: { ...f, motivo: f.motivo || null } });
            setF({ nombres: '', numero_documento: '', visita_a: '', motivo: '' });
            setDia(hoy());
            recargar();
          });
        }}>
          <div className="campos">
            <Campo etiqueta="Nombre del visitante"><input value={f.nombres} onChange={(e) => setF({ ...f, nombres: e.target.value })} required minLength={3} maxLength={200} /></Campo>
            <Campo etiqueta="DNI o documento"><input value={f.numero_documento} onChange={(e) => setF({ ...f, numero_documento: e.target.value })} required minLength={6} maxLength={15} /></Campo>
            <Campo etiqueta="A quién visita"><input value={f.visita_a} onChange={(e) => setF({ ...f, visita_a: e.target.value })} required maxLength={150} placeholder="Persona o área" /></Campo>
            <Campo etiqueta="Motivo"><input value={f.motivo} onChange={(e) => setF({ ...f, motivo: e.target.value })} maxLength={200} /></Campo>
          </div>
          <div className="acciones"><button type="submit" className="boton" disabled={accion.enviando}>Registrar ingreso</button></div>
        </form>
      )}
      <div className="filtros"><Campo etiqueta="Día"><input type="date" value={dia} onChange={(e) => setDia(e.target.value)} /></Campo></div>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hubo visitas ese día.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Visitante</th><th>Visita a</th><th>Ingreso</th><th>Salida</th></tr></thead><tbody>
          {datos.data.map((v) => (
            <tr key={v.id}>
              <td>{v.nombres}<br /><small className="ayuda">{v.numero_documento}</small></td>
              <td>{v.visita_a}{v.motivo && <><br /><small className="ayuda">{v.motivo}</small></>}</td>
              <td>{fechaHora(v.ingreso_at)}</td>
              <td>{v.salida_at ? fechaHora(v.salida_at) : registra ? <button type="button" className="boton boton-borde boton-chico" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => { await pedir(`/porteria/visitas/${v.id}/salida`, { metodo: 'POST' }); recargar(); })}>Marcar salida</button> : 'Dentro'}</td>
            </tr>
          ))}
        </tbody></table></div></div>
      )}
    </>
  );
}

/** EDUN-187 / EDUN-189 · La familia: quién puede recoger a sus hijos y salidas anticipadas. */
export function RecojoFamilia() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const familia = useCarga(() => pedir<{ hijos: { id: number; nombres: string; apellido_paterno: string }[] }>('/me/familia'));
  const aut = useCarga(() => pedir<{ data: Autorizado[] }>('/familia/autorizados'));
  const sal = useCarga(() => pedir<{ data: Anticipada[] }>('/familia/salidas'));
  const hijos = familia.datos?.hijos ?? [];
  const [a, setA] = useState({ alumno_id: '', nombres: '', numero_documento: '', parentesco: '', vigente_hasta: '' });
  const [s, setS] = useState({ alumno_id: '', fecha: hoy(), hora: '12:00', motivo: '', por_autorizado_id: '' });
  const accion = useAccion();
  const hijoA = a.alumno_id || String(hijos[0]?.id ?? '');
  const hijoS = s.alumno_id || String(hijos[0]?.id ?? '');
  const autorizadosDe = (aut.datos?.data ?? []).filter((x) => String(x.alumno_id) === hijoS && x.vigente);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Recojo y salidas" descripcion="Indica quién más puede recoger a tus hijos (con su DNI) y pide una salida anticipada: portería solo entrega a personas autorizadas." />
      <Aviso tipo="error">{familia.error ?? aut.error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tarjeta"><h2>Personas autorizadas a recoger</h2>
        {(aut.datos?.data ?? []).length > 0 && (
          <div className="tabla-caja"><table className="tabla"><thead><tr><th>Hijo</th><th>Persona</th><th>DNI</th><th>Vigencia</th><th /></tr></thead><tbody>
            {aut.datos!.data.map((x) => (
              <tr key={x.id}>
                <td>{x.alumno}</td><td>{x.nombres} ({x.parentesco})</td><td>{x.numero_documento}</td>
                <td>{x.vigente_hasta ? `Hasta ${fecha(x.vigente_hasta)}` : 'Sin fecha'} {!x.vigente && <span className="insignia insignia-gris">No vigente</span>}</td>
                <td><button type="button" className="boton boton-borde boton-chico" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => {
                  await pedir(`/familia/autorizados/${x.id}`, { metodo: 'PUT', cuerpo: { alumno_id: x.alumno_id, nombres: x.nombres, numero_documento: x.numero_documento, parentesco: x.parentesco, vigente_hasta: x.vigente_hasta, activo: !x.activo } });
                  aut.recargar();
                })}>{x.activo ? 'Desactivar' : 'Activar'}</button></td>
              </tr>
            ))}
          </tbody></table></div>
        )}
        <form className="campos" onSubmit={(e) => {
          e.preventDefault();
          void accion.ejecutar(async () => {
            await pedir('/familia/autorizados', { metodo: 'POST', cuerpo: { ...a, alumno_id: Number(hijoA), vigente_hasta: a.vigente_hasta || null } });
            setA({ ...a, nombres: '', numero_documento: '', parentesco: '', vigente_hasta: '' });
            aut.recargar();
            accion.setOk('Persona autorizada. Portería la verificará con su DNI.');
          });
        }}>
          {hijos.length > 1 && <Campo etiqueta="Hijo"><select value={hijoA} onChange={(e) => setA({ ...a, alumno_id: e.target.value })}>{hijos.map((h) => <option key={h.id} value={h.id}>{h.nombres}</option>)}</select></Campo>}
          <Campo etiqueta="Nombre completo"><input value={a.nombres} onChange={(e) => setA({ ...a, nombres: e.target.value })} required minLength={3} /></Campo>
          <Campo etiqueta="DNI o carné de extranjería"><input value={a.numero_documento} onChange={(e) => setA({ ...a, numero_documento: e.target.value })} required /></Campo>
          <Campo etiqueta="Parentesco"><input value={a.parentesco} onChange={(e) => setA({ ...a, parentesco: e.target.value })} required placeholder="Tía, abuelo, vecina…" /></Campo>
          <Campo etiqueta="Vigente hasta (opcional)"><input type="date" value={a.vigente_hasta} onChange={(e) => setA({ ...a, vigente_hasta: e.target.value })} /></Campo>
          <div className="acciones"><button type="submit" className="boton" disabled={accion.enviando || !hijoA}>Agregar</button></div>
        </form>
      </div>
      <div className="tarjeta"><h2>Pedir una salida anticipada</h2>
        <form className="campos" onSubmit={(e) => {
          e.preventDefault();
          void accion.ejecutar(async () => {
            await pedir('/familia/salidas', { metodo: 'POST', cuerpo: { ...s, alumno_id: Number(hijoS), por_autorizado_id: s.por_autorizado_id ? Number(s.por_autorizado_id) : null } });
            setS({ ...s, motivo: '' });
            sal.recargar();
            accion.setOk('Salida pedida. Te avisaremos cuando el colegio la confirme.');
          });
        }}>
          {hijos.length > 1 && <Campo etiqueta="Hijo"><select value={hijoS} onChange={(e) => setS({ ...s, alumno_id: e.target.value, por_autorizado_id: '' })}>{hijos.map((h) => <option key={h.id} value={h.id}>{h.nombres}</option>)}</select></Campo>}
          <Campo etiqueta="Día"><input type="date" value={s.fecha} min={hoy()} onChange={(e) => setS({ ...s, fecha: e.target.value })} required /></Campo>
          <Campo etiqueta="Hora"><input type="time" value={s.hora} onChange={(e) => setS({ ...s, hora: e.target.value })} required /></Campo>
          <Campo etiqueta="Quién recoge"><select value={s.por_autorizado_id} onChange={(e) => setS({ ...s, por_autorizado_id: e.target.value })}><option value="">Yo (apoderado)</option>{autorizadosDe.map((x) => <option key={x.id} value={x.id}>{x.nombres} ({x.parentesco})</option>)}</select></Campo>
          <Campo etiqueta="Motivo" ancho><input value={s.motivo} onChange={(e) => setS({ ...s, motivo: e.target.value })} required minLength={3} maxLength={200} placeholder="Ej.: cita médica" /></Campo>
          <div className="acciones"><button type="submit" className="boton" disabled={accion.enviando || !hijoS}>Pedir salida</button></div>
        </form>
        {(sal.datos?.data ?? []).length > 0 && (
          <ul className="lista-simple">{sal.datos!.data.map((x) => (
            <li key={x.id}>{fecha(x.fecha)} {x.hora.slice(0, 5)} · {x.alumno} con {x.recoge} · <span className={`insignia ${ESTADO[x.estado].clase}`}>{ESTADO[x.estado].texto}</span>{x.respuesta && <small className="ayuda"> · {x.respuesta}</small>}
              {['solicitada', 'autorizada'].includes(x.estado) && <> <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/familia/salidas/${x.id}/anulacion`, { metodo: 'POST' }); sal.recargar(); })}>Anular</button></>}
            </li>
          ))}</ul>
        )}
      </div>
    </>
  );
}
