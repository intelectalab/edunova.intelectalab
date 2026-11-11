import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { useAccion, useCarga } from '../panel/utilidades';

type Periodo = { id: number; periodo_id: number; periodo: string; areas: { area: string; competencias: { numero: number; competencia: string; valor: string | null; conclusion_descriptiva: string | null }[] }[] };
type Hijo = { alumno_id: number; alumno: string; periodos: Periodo[] };

/** EDUN-100 · CAL-H10 Notas y libreta por hijo y periodo (sin bloqueo por deuda). */
export function MisNotas() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error } = useCarga(() => pedir<{ hijos: Hijo[] }>('/familia/notas'));
  const accion = useAccion();
  const [sel, setSel] = useState<Record<number, number>>({});
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Notas y libretas" descripcion="Las notas de cada periodo aparecen cuando el colegio publica la libreta." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {datos?.hijos.length === 0 && <p className="vacio">Aún no hay libretas publicadas.</p>}
      <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
        {datos?.hijos.map((h) => {
          const p = h.periodos.find((x) => x.periodo_id === sel[h.alumno_id]) ?? h.periodos[h.periodos.length - 1];
          return (
            <div key={h.alumno_id} className="tarjeta">
              <div className="titulo-fila">
                <h2>{h.alumno}</h2>
                <div className="filtros" style={{ marginBottom: 0, alignItems: 'end' }}>
                  <Campo etiqueta="Periodo"><select value={p.periodo_id} onChange={(e) => setSel({ ...sel, [h.alumno_id]: Number(e.target.value) })}>{h.periodos.map((x) => <option key={x.periodo_id} value={x.periodo_id}>{x.periodo}</option>)}</select></Campo>
                  <button type="button" className="boton boton-borde" onClick={() => accion.ejecutar(async () => { window.open((await pedir<{ url: string }>(`/familia/libretas/${p.id}`)).url, '_blank', 'noopener'); })}>Descargar libreta</button>
                </div>
              </div>
              {p.areas.map((a) => (
                <div key={a.area} style={{ marginTop: 10 }}>
                  <h3 style={{ margin: '6px 0' }}>{a.area}</h3>
                  {a.competencias.map((k) => (
                    <p key={k.numero} style={{ margin: '2px 0' }}>
                      <span className={`insignia ${k.valor === 'C' ? 'insignia-rojo' : k.valor === 'AD' || k.valor === 'A' ? 'insignia-ok' : 'insignia-ambar'}`}>{k.valor ?? '—'}</span> C{k.numero}. {k.competencia}
                      {k.conclusion_descriptiva && <><br /><small className="ayuda">{k.conclusion_descriptiva}</small></>}
                    </p>
                  ))}
                </div>
              ))}
            </div>
          );
        })}
      </div>
    </>
  );
}
