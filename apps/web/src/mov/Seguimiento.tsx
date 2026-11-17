import { useEffect } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Titulo } from '../panel/ui';
import { fechaHora, useCarga } from '../panel/utilidades';

type Monitor = { id: number; ruta: string; turno: string; placa: string | null; conductor: string | null; estado: string; sentido: string; iniciado_at: string; finalizado_at: string | null; ultima_posicion_at: string | null; minutos_sin_senal: number | null; sin_senal: boolean; siguiente_paradero: string | null; retraso_min: number; mapa_url: string | null };
type Hijo = { asignacion_id: number; alumno: string; ruta: string; placa: string | null; paradero: string; hora_estimada: string | null; paradero_mapa_url: string | null; recorrido: { estado: string; sentido: string; iniciado_at: string | null; finalizado_at: string | null } | null; en_vivo: { posicion_at: string; distancia_m: number | null; llegada_min: number | null; mapa_url: string } | null; bitacora: { tipo: string; ocurrido_at: string }[] };
const EVENTO: Record<string, string> = { subio: 'Subió', bajo: 'Bajó', no_subio: 'No subió' };

/** EDUN-167 · Monitor de recorridos: unidades en curso, retraso y alerta por pérdida de señal (se actualiza cada 30 s). */
export function MonitorRecorridos() {
  const { pedir } = useSesion();
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Monitor[] }>('/movilidad/monitor'));
  useEffect(() => {
    const t = setInterval(recargar, 30000);
    return () => clearInterval(t);
  }, [recargar]);
  return (
    <>
      <Aviso tipo="error">{error}</Aviso>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hay recorridos hoy.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Ruta</th><th>Unidad</th><th>Estado</th><th>Última señal</th><th>Siguiente paradero</th><th /></tr></thead><tbody>
          {datos.data.map((r) => (
            <tr key={r.id}>
              <td>{r.ruta}<br /><small className="ayuda">{r.sentido === 'ida' ? 'Ida' : 'Vuelta'} · {r.conductor ?? 'Sin conductor'}</small></td>
              <td>{r.placa ?? '—'}</td>
              <td>{r.estado === 'en_curso' ? <span className="insignia insignia-azul">En curso</span> : <span className="insignia insignia-ok">Terminó {fechaHora(r.finalizado_at)}</span>}</td>
              <td>{r.estado === 'en_curso' ? <>{fechaHora(r.ultima_posicion_at)}{r.sin_senal && <><br /><span className="insignia insignia-rojo">Sin señal hace {r.minutos_sin_senal} min</span></>}</> : '—'}</td>
              <td>{r.siguiente_paradero ?? '—'}{r.retraso_min > 0 && <><br /><span className="insignia insignia-ambar">{r.retraso_min} min de retraso</span></>}</td>
              <td>{r.mapa_url && <a href={r.mapa_url} target="_blank" rel="noopener noreferrer">Ver en el mapa</a>}</td>
            </tr>
          ))}
        </tbody></table></div></div>
      )}
      <p className="ayuda">Si una unidad en curso no envía su ubicación por más de 3 minutos, movilidad recibe una alerta.</p>
    </>
  );
}

/** EDUN-166 · La familia sigue la movilidad de sus hijos (solo durante el recorrido). */
export function MovilidadFamilia() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Hijo[] }>('/familia/movilidad'));
  useEffect(() => {
    const t = setInterval(recargar, 20000);
    return () => clearInterval(t);
  }, [recargar]);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Movilidad" descripcion="Durante el recorrido ves dónde está la unidad y cuánto falta para tu paradero. Te avisamos cuando está cerca." />
      <Aviso tipo="error">{error}</Aviso>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">Tus hijos no tienen movilidad escolar.</p> : datos.data.map((h) => (
        <div key={h.asignacion_id} className="tarjeta">
          <h2>{h.alumno} · {h.ruta}{h.placa ? ` (${h.placa})` : ''}</h2>
          <p>Paradero: {h.paradero_mapa_url ? <a href={h.paradero_mapa_url} target="_blank" rel="noopener noreferrer">{h.paradero}</a> : h.paradero}{h.hora_estimada ? ` · hora estimada ${h.hora_estimada}` : ''}</p>
          {h.en_vivo ? (
            <Aviso tipo="ok">En camino: {h.en_vivo.distancia_m !== null ? `a ${h.en_vivo.distancia_m >= 1000 ? `${(h.en_vivo.distancia_m / 1000).toFixed(1)} km` : `${h.en_vivo.distancia_m} m`}` : ''}{h.en_vivo.llegada_min !== null ? `, llega en unos ${h.en_vivo.llegada_min} min` : ''}. <a href={h.en_vivo.mapa_url} target="_blank" rel="noopener noreferrer">Ver en el mapa</a> · señal {fechaHora(h.en_vivo.posicion_at)}</Aviso>
          ) : <p className="ayuda">{h.recorrido?.estado === 'finalizado' ? `El recorrido de hoy terminó ${fechaHora(h.recorrido.finalizado_at)}.` : 'La unidad todavía no inicia el recorrido.'}</p>}
          {h.bitacora.length > 0 && <ul className="lista-simple">{h.bitacora.map((e, i) => <li key={i}>{EVENTO[e.tipo] ?? e.tipo} · {fechaHora(e.ocurrido_at)}</li>)}</ul>}
        </div>
      ))}
    </>
  );
}
