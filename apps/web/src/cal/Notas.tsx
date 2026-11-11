import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Pestanas, Titulo } from '../panel/ui';
import { fecha, useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from '../adm/anios';
import { SelectorAnio } from '../adm/SelectorAnio';
import { esValida, type Actividades, type Carga, type Celda, type Grilla, type Periodo } from './tipos';

function CeldaNota({ celda, escala, valores, deshabilitada, alGuardar, alConclusion }: {
  celda: Celda; escala: string; valores: string[] | null; deshabilitada: boolean; alGuardar: (v: string) => void; alConclusion: () => void;
}) {
  const [texto, setTexto] = useState(celda.valor ?? '');
  const invalida = !esValida(texto, escala);
  const falta = !celda.valor;
  const cSin = celda.valor === 'C' && !celda.conclusion?.trim();
  const estilo = { width: 64, borderColor: invalida || cSin ? 'var(--en-rojo)' : falta ? 'var(--en-ambar)' : undefined, background: invalida || cSin ? 'var(--en-rojo-suave)' : undefined };
  return (
    <div style={{ display: 'flex', gap: 4, alignItems: 'center' }} title={celda.sugerencia ? `Sugerida por actividades: ${celda.sugerencia}` : undefined}>
      {valores ? (
        <select aria-label="Nota" value={celda.valor ?? ''} disabled={deshabilitada} style={estilo} onChange={(e) => alGuardar(e.target.value)}>
          <option value="">—</option>
          {valores.map((v) => <option key={v} value={v}>{v}</option>)}
        </select>
      ) : (
        <input aria-label="Nota" inputMode="numeric" maxLength={2} value={texto} disabled={deshabilitada} style={estilo}
          onChange={(e) => setTexto(e.target.value)} onBlur={() => texto !== (celda.valor ?? '') && !invalida && alGuardar(texto)} />
      )}
      {celda.valor === 'C' && <button type="button" className={`boton boton-chico ${cSin ? '' : 'boton-borde'}`} aria-label="Conclusión descriptiva" onClick={alConclusion}>✎</button>}
      {!celda.valor && celda.sugerencia && <small className="ayuda">({celda.sugerencia})</small>}
    </div>
  );
}

function GrillaNotas({ carga, periodo }: { carga: number; periodo: number }) {
  const { pedir, estado } = useSesion();
  const editable = puede(estado!.colegio_activo!, 'cal.notas.registrar');
  const { datos, setDatos, error } = useCarga(() => pedir<Grilla>(`/notas/cargas/${carga}?periodo_id=${periodo}`), [carga, periodo]);
  const accion = useAccion();
  const [conclusion, setConclusion] = useState<{ clave: string; alumno: number; competencia: number; texto: string } | null>(null);
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;

  const guardar = (alumno: number, competencia: number, valor: string | null, texto?: string | null) => accion.ejecutar(async () => {
    const clave = `${alumno}-${competencia}`;
    const actual = datos.celdas[clave];
    const r = await pedir<{ valor: string | null; conclusion: string | null; observacion: string | null }>(`/notas/cargas/${carga}/celda`, {
      metodo: 'PUT', cuerpo: { periodo_id: periodo, alumno_id: alumno, competencia_id: competencia, valor, conclusion: texto === undefined ? actual.conclusion : texto },
    });
    const celdas = { ...datos.celdas, [clave]: { ...actual, valor: r.valor, conclusion: r.conclusion } };
    setDatos({ ...datos, celdas, resumen: resumen(celdas) });
    if (r.valor === 'C' && !r.conclusion) setConclusion({ clave, alumno, competencia, texto: '' });
  });

  return (
    <div className="tarjeta">
      <p className="ayuda">
        {datos.carga.area} · {datos.carga.seccion} · {datos.periodo.nombre} · escala {datos.escala === 'literal' ? 'AD, A, B, C' : '00 a 20'}
        {datos.periodo.fecha_limite_notas && ` · fecha límite ${fecha(datos.periodo.fecha_limite_notas)}`} · Se guarda al cambiar cada nota.
      </p>
      {datos.cerrado && <Aviso tipo="ambar">El periodo está cerrado para esta sección: las notas no se pueden modificar.</Aviso>}
      <Aviso tipo={datos.resumen.completo ? 'ok' : 'ambar'}>
        {datos.resumen.llenas} de {datos.resumen.total} notas{datos.resumen.vacias ? ` · ${datos.resumen.vacias} vacías` : ''}{datos.resumen.c_sin_conclusion ? ` · ${datos.resumen.c_sin_conclusion} C sin conclusión` : ''}
      </Aviso>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="tabla-caja">
        <table className="tabla">
          <thead>
            <tr><th>Alumno</th>{datos.competencias.map((k) => <th key={k.id} title={k.nombre}>C{k.numero} {k.nombre_corto ?? ''}</th>)}</tr>
          </thead>
          <tbody>
            {datos.alumnos.map((a) => (
              <tr key={a.id}>
                <td>{a.nombre}</td>
                {datos.competencias.map((k) => {
                  const clave = `${a.id}-${k.id}`;
                  return (
                    <td key={k.id}>
                      <CeldaNota key={datos.celdas[clave].valor ?? ''} celda={datos.celdas[clave]} escala={datos.escala} valores={datos.valores} deshabilitada={datos.cerrado || !editable}
                        alGuardar={(v) => guardar(a.id, k.id, v || null)} alConclusion={() => setConclusion({ clave, alumno: a.id, competencia: k.id, texto: datos.celdas[clave].conclusion ?? '' })} />
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
        {datos.alumnos.length === 0 && <p className="vacio">La sección no tiene alumnos matriculados.</p>}
      </div>
      {conclusion && (
        <Dialogo titulo="Conclusión descriptiva" alCerrar={() => setConclusion(null)}>
          <p className="ayuda">Con C es obligatoria (SIAGIE): describe el avance y lo que necesita reforzar.</p>
          <textarea className="control" aria-label="Conclusión descriptiva" style={{ width: '100%', height: 140, padding: 10 }} value={conclusion.texto} onChange={(e) => setConclusion({ ...conclusion, texto: e.target.value })} />
          <div className="acciones">
            <button type="button" className="boton" disabled={!conclusion.texto.trim()} onClick={async () => { await guardar(conclusion.alumno, conclusion.competencia, datos.celdas[conclusion.clave].valor, conclusion.texto); setConclusion(null); }}>Guardar</button>
          </div>
        </Dialogo>
      )}
    </div>
  );
}

function resumen(celdas: Record<string, Celda>) {
  const lista = Object.values(celdas);
  const llenas = lista.filter((c) => c.valor).length;
  const cSin = lista.filter((c) => c.valor === 'C' && !c.conclusion?.trim()).length;
  return { total: lista.length, llenas, vacias: lista.length - llenas, c_sin_conclusion: cSin, completo: lista.length > 0 && llenas === lista.length && cSin === 0 };
}

function ActividadesCurso({ carga, periodo }: { carga: number; periodo: number }) {
  const { pedir } = useSesion();
  const { datos, setDatos, error, recargar } = useCarga(() => pedir<Actividades>(`/notas/cargas/${carga}/actividades?periodo_id=${periodo}`), [carga, periodo]);
  const accion = useAccion();
  const [nueva, setNueva] = useState({ nombre: '', competencia_id: 0, fecha: '' });
  const [abierta, setAbierta] = useState<number | null>(null);
  const [notas, setNotas] = useState<Record<number, { valor: string; comentario: string }>>({});
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;
  const actividad = datos.actividades.find((a) => a.id === abierta);
  return (
    <div className="tarjeta">
      <p className="ayuda">Las notas de las actividades son insumo: en la grilla verás la nota sugerida y tú confirmas la oficial.</p>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="filtros" style={{ alignItems: 'end' }}>
        <Campo etiqueta="Actividad"><input value={nueva.nombre} placeholder="Práctica calificada 1" onChange={(e) => setNueva({ ...nueva, nombre: e.target.value })} /></Campo>
        <Campo etiqueta="Competencia">
          <select value={nueva.competencia_id} onChange={(e) => setNueva({ ...nueva, competencia_id: Number(e.target.value) })}>
            <option value={0}>Elige</option>
            {datos.competencias.map((k) => <option key={k.id} value={k.id}>C{k.numero} {k.nombre_corto ?? k.nombre.slice(0, 40)}</option>)}
          </select>
        </Campo>
        <Campo etiqueta="Fecha"><input type="date" value={nueva.fecha} onChange={(e) => setNueva({ ...nueva, fecha: e.target.value })} /></Campo>
        <button type="button" className="boton" disabled={!nueva.nombre.trim() || !nueva.competencia_id || accion.enviando} onClick={() => accion.ejecutar(async () => {
          setDatos(await pedir<Actividades>(`/notas/cargas/${carga}/actividades`, { metodo: 'POST', cuerpo: { periodo_id: periodo, ...nueva, fecha: nueva.fecha || null } }));
          setNueva({ nombre: '', competencia_id: 0, fecha: '' });
        })}>Agregar</button>
      </div>
      <div className="tabla-caja">
        <table className="tabla">
          <thead><tr><th>Actividad</th><th>Competencia</th><th>Fecha</th><th>Notas</th><th /></tr></thead>
          <tbody>
            {datos.actividades.map((a) => (
              <tr key={a.id}>
                <td>{a.nombre}</td><td>C{datos.competencias.find((k) => k.id === a.competencia_id)?.numero}</td><td>{a.fecha ? fecha(a.fecha) : '—'}</td>
                <td>{Object.keys(a.notas).length} de {datos.alumnos.length}</td>
                <td>
                  <button type="button" className="boton boton-chico boton-borde" onClick={() => { setAbierta(a.id); setNotas(Object.fromEntries(Object.entries(a.notas).map(([k, v]) => [Number(k), { valor: v.valor, comentario: v.comentario ?? '' }]))); }}>Calificar</button>{' '}
                  <button type="button" className="boton boton-chico boton-borde" onClick={() => window.confirm(`¿Eliminar «${a.nombre}» y sus notas?`) && accion.ejecutar(async () => { await pedir(`/notas/actividades/${a.id}`, { metodo: 'DELETE' }); recargar(); })}>Eliminar</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {datos.actividades.length === 0 && <p className="vacio">Aún no hay actividades en este periodo.</p>}
      </div>
      {actividad && (
        <Dialogo titulo={actividad.nombre} alCerrar={() => setAbierta(null)}>
          <div className="tabla-caja">
            <table className="tabla">
              <thead><tr><th>Alumno</th><th>Nota</th><th>Comentario</th></tr></thead>
              <tbody>
                {datos.alumnos.map((al) => {
                  const n = notas[al.id] ?? { valor: '', comentario: '' };
                  return (
                    <tr key={al.id}>
                      <td>{al.nombre}</td>
                      <td>
                        <input aria-label={`Nota de ${al.nombre}`} maxLength={2} style={{ width: 60, borderColor: esValida(n.valor, datos.escala) ? undefined : 'var(--en-rojo)' }} value={n.valor}
                          onChange={(e) => setNotas({ ...notas, [al.id]: { ...n, valor: e.target.value.toUpperCase() } })} />
                      </td>
                      <td><input aria-label={`Comentario de ${al.nombre}`} value={n.comentario} maxLength={500} onChange={(e) => setNotas({ ...notas, [al.id]: { ...n, comentario: e.target.value } })} /></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando || Object.values(notas).some((n) => !esValida(n.valor, datos.escala))} onClick={() => accion.ejecutar(async () => {
              await pedir(`/notas/actividades/${actividad.id}/notas`, { metodo: 'PUT', cuerpo: { notas } });
              setAbierta(null);
              recargar();
            })}>Guardar notas</button>
          </div>
        </Dialogo>
      )}
    </div>
  );
}

/** EDUN-91 / EDUN-93 · CAL-01 Registro de notas por competencia y actividades de evaluación. */
export function Notas() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { anios, anio, setAnio } = useAnioEscolar();
  const { datos, error } = useCarga(() => (anio ? pedir<{ cargas: Carga[]; periodos: Periodo[] }>(`/notas/cargas?anio_escolar_id=${anio}`) : Promise.resolve({ cargas: [], periodos: [] })), [anio]);
  // Enlace directo desde la validación del SIAGIE: #/notas/{curso}/{periodo}.
  const [carga, setCarga] = useState<number | null>(() => Number(window.location.hash.split('/')[2]) || null);
  const [periodo, setPeriodo] = useState<number | null>(() => Number(window.location.hash.split('/')[3]) || null);
  const [vista, setVista] = useState<'notas' | 'actividades'>('notas');
  const cargaActual = carga ?? datos?.cargas[0]?.id ?? null;
  const periodoActual = periodo ?? datos?.periodos.find((p) => p.estado === 'abierto')?.id ?? datos?.periodos[0]?.id ?? null;
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Académico`} titulo="Notas" descripcion="Nota oficial por competencia y periodo. Vacías en ámbar; inválidas o C sin conclusión en rojo." />
      <Aviso tipo="error">{error}</Aviso>
      <div className="filtros">
        {anios.length > 0 && <SelectorAnio anios={anios} anio={anio} alCambiar={(id) => { setAnio(id); setCarga(null); setPeriodo(null); }} />}
        <Campo etiqueta="Curso">
          <select value={cargaActual ?? ''} onChange={(e) => setCarga(Number(e.target.value))}>
            {datos?.cargas.map((c) => <option key={c.id} value={c.id}>{c.area} · {c.seccion}</option>)}
          </select>
        </Campo>
        <Campo etiqueta="Periodo">
          <select value={periodoActual ?? ''} onChange={(e) => setPeriodo(Number(e.target.value))}>
            {datos?.periodos.map((p) => <option key={p.id} value={p.id}>{p.nombre}{p.estado === 'cerrado' ? ' (cerrado)' : ''}</option>)}
          </select>
        </Campo>
      </div>
      {datos && datos.cargas.length === 0 && <Aviso tipo="ambar">No tienes cursos asignados en este año.</Aviso>}
      {cargaActual && periodoActual && (
        <>
          <Pestanas opciones={[['notas', 'Notas por competencia'], ['actividades', 'Actividades']]} actual={vista} alCambiar={setVista} />
          <div style={{ marginTop: 12 }}>{vista === 'notas' ? <GrillaNotas carga={cargaActual} periodo={periodoActual} /> : <ActividadesCurso carga={cargaActual} periodo={periodoActual} />}</div>
        </>
      )}
    </>
  );
}
