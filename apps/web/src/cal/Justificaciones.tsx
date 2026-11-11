import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Justificacion = {
  id: number; alumno: string; seccion: string | null; fecha_desde: string; fecha_hasta: string; motivo: string; estado: 'pendiente' | 'aceptada' | 'rechazada';
  respuesta: string | null; created_at: string; resuelto_at: string | null; dias_justificados: number; faltas: number; tiene_sustento: boolean;
};

const ESTADOS: Record<Justificacion['estado'], { texto: string; clase: string }> = {
  pendiente: { texto: 'Pendiente', clase: 'insignia-ambar' },
  aceptada: { texto: 'Aceptada', clase: 'insignia-ok' },
  rechazada: { texto: 'Rechazada', clase: 'insignia-rojo' },
};
const HOY = new Date().toISOString().slice(0, 10);
const dias = (j: Justificacion) => (j.fecha_desde === j.fecha_hasta ? j.fecha_desde : `${j.fecha_desde} al ${j.fecha_hasta}`);

function VerSustento({ id }: { id: number }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  return (
    <button type="button" className="boton boton-borde boton-chico" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => {
      window.open((await pedir<{ url: string }>(`/justificaciones/${id}/sustento`)).url, '_blank', 'noopener');
    })}>Ver sustento</button>
  );
}

/** EDUN-96 · Bandeja del tutor (sus secciones), coordinación y dirección: aceptar (F → J) o rechazar. */
export function JustificacionesTutor() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [filtro, setFiltro] = useState<'pendiente' | 'aceptada' | 'rechazada' | ''>('pendiente');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Justificacion[] }>(`/asistencia/justificaciones${filtro ? `?estado=${filtro}` : ''}`), [filtro]);
  const accion = useAccion();
  const resolver = (j: Justificacion, decision: 'aceptar' | 'rechazar') => {
    const respuesta = decision === 'rechazar' ? window.prompt('Explica a la familia por qué se rechaza:') : null;
    if (decision === 'rechazar' && !respuesta?.trim()) return;
    accion.ejecutar(async () => {
      const r = await pedir<Justificacion>(`/asistencia/justificaciones/${j.id}`, { metodo: 'POST', cuerpo: { decision, respuesta } });
      recargar();
      accion.setOk(decision === 'aceptar' ? `Aceptada: ${r.dias_justificados} falta(s) de ${j.alumno} pasaron a justificadas.` : 'Rechazada. La familia recibió tu explicación.');
    });
  };
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Académico`} titulo="Justificaciones de inasistencia" descripcion="Las familias las envían desde la app con el sustento. Al aceptarlas, las faltas (F) de esos días pasan a justificadas (J)." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="filtros">
        <Campo etiqueta="Mostrar">
          <select value={filtro} onChange={(e) => setFiltro(e.target.value as typeof filtro)}>
            <option value="pendiente">Pendientes</option>
            <option value="aceptada">Aceptadas</option>
            <option value="rechazada">Rechazadas</option>
            <option value="">Todas</option>
          </select>
        </Campo>
      </div>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hay justificaciones {filtro ? `${ESTADOS[filtro].texto.toLowerCase()}s` : ''}.</p> : (
        <div className="tarjeta"><div className="tabla-caja">
          <table className="tabla"><thead><tr><th>Alumno</th><th>Días</th><th>Motivo</th><th>Faltas</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
            {datos.data.map((j) => (
              <tr key={j.id}>
                <td>{j.alumno}<br /><small className="ayuda">{j.seccion} · enviada {fechaHora(j.created_at)}</small></td>
                <td>{dias(j)}</td>
                <td>{j.motivo}{j.respuesta && <><br /><small className="ayuda">Respuesta: {j.respuesta}</small></>}</td>
                <td>{j.estado === 'aceptada' ? `${j.dias_justificados} justificada(s)` : j.faltas}</td>
                <td><span className={`insignia ${ESTADOS[j.estado].clase}`}>{ESTADOS[j.estado].texto}</span></td>
                <td><div className="acciones">
                  {j.tiene_sustento && <VerSustento id={j.id} />}
                  {j.estado === 'pendiente' && <>
                    <button type="button" className="boton boton-chico" disabled={accion.enviando} onClick={() => resolver(j, 'aceptar')}>Aceptar</button>
                    <button type="button" className="boton boton-borde boton-chico" disabled={accion.enviando} onClick={() => resolver(j, 'rechazar')}>Rechazar</button>
                  </>}
                </div></td>
              </tr>
            ))}
          </tbody></table>
        </div></div>
      )}
    </>
  );
}

/** EDUN-96 · La familia también puede justificar desde la web (con foto o PDF del sustento). */
export function MisJustificaciones() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const familia = useCarga(() => pedir<{ hijos: { id: number; nombres: string; apellido_paterno: string }[] }>('/me/familia'));
  const { datos, recargar } = useCarga(() => pedir<{ data: Justificacion[] }>('/familia/justificaciones'));
  const accion = useAccion();
  const [f, setF] = useState({ alumno_id: '', fecha_desde: HOY, fecha_hasta: HOY, motivo: '' });
  const [archivo, setArchivo] = useState<File | null>(null);
  const hijos = familia.datos?.hijos ?? [];
  const alumno = f.alumno_id || String(hijos[0]?.id ?? '');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Justificar una inasistencia" descripcion="Envía el motivo y, si tienes, la foto o PDF del sustento (receta, constancia). El tutor la revisa y te avisamos." />
      <Aviso tipo="error">{familia.error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <form className="tarjeta" onSubmit={(e) => {
        e.preventDefault();
        accion.ejecutar(async () => {
          const cuerpo = new FormData();
          cuerpo.append('alumno_id', alumno);
          cuerpo.append('fecha_desde', f.fecha_desde);
          cuerpo.append('fecha_hasta', f.fecha_hasta);
          cuerpo.append('motivo', f.motivo);
          if (archivo) cuerpo.append('sustento', archivo);
          await pedir('/familia/justificaciones', { metodo: 'POST', cuerpo });
          setF({ ...f, motivo: '' });
          setArchivo(null);
          recargar();
          accion.setOk('Justificación enviada. El tutor la revisará.');
        });
      }}>
        <div className="campos">
        {hijos.length > 1 && <Campo etiqueta="Hijo"><select value={alumno} onChange={(e) => setF({ ...f, alumno_id: e.target.value })}>{hijos.map((h) => <option key={h.id} value={h.id}>{h.nombres} {h.apellido_paterno}</option>)}</select></Campo>}
        <Campo etiqueta="Desde"><input type="date" value={f.fecha_desde} onChange={(e) => setF({ ...f, fecha_desde: e.target.value })} required /></Campo>
        <Campo etiqueta="Hasta"><input type="date" value={f.fecha_hasta} min={f.fecha_desde} onChange={(e) => setF({ ...f, fecha_hasta: e.target.value })} required /></Campo>
        <Campo etiqueta="Motivo" ancho><textarea value={f.motivo} onChange={(e) => setF({ ...f, motivo: e.target.value })} minLength={5} maxLength={500} required rows={3} /></Campo>
        <Campo etiqueta="Sustento (opcional)" ayuda="Foto o PDF de hasta 5 MB." ancho><input type="file" accept="image/*,application/pdf" onChange={(e) => setArchivo(e.target.files?.[0] ?? null)} /></Campo>
        </div>
        <div className="acciones"><button type="submit" className="boton" disabled={accion.enviando || !alumno}>Enviar al tutor</button></div>
      </form>
      {datos && datos.data.length > 0 && (
        <div className="tarjeta"><h2>Mis justificaciones</h2><div className="tabla-caja">
          <table className="tabla"><thead><tr><th>Alumno</th><th>Días</th><th>Motivo</th><th>Estado</th></tr></thead><tbody>
            {datos.data.map((j) => <tr key={j.id}><td>{j.alumno}</td><td>{dias(j)}</td><td>{j.motivo}{j.respuesta && <><br /><small className="ayuda">{j.respuesta}</small></>}</td><td><span className={`insignia ${ESTADOS[j.estado].clase}`}>{ESTADOS[j.estado].texto}</span></td></tr>)}
          </tbody></table>
        </div></div>
      )}
    </>
  );
}
