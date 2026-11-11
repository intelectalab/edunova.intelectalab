import { useEffect, useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Pestanas, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from '../adm/anios';
import { SelectorAnio } from '../adm/SelectorAnio';
import type { Periodo } from '../cal/tipos';

type Proceso = {
  id: number; tipo: string; estado: string; total_filas: number; creados: number; actualizados: number; con_error: number; created_at: string; finalizado_at: string | null;
  archivo_resultado_id: number | null; seccion: string | null; periodo: string | null; archivo: string | null; usuario: string | null;
  advertencias?: unknown; errores?: { fila: number; mensaje: string }[];
};
type ErrorNota = { tipo: string; alumno: string; mensaje: string; enlace: string };
type Seccion = { id: number; nombre: string };

const ESTADO: Record<string, string> = { pendiente: 'insignia-ambar', procesando: 'insignia-ambar', completado: 'insignia-ok', con_errores: 'insignia-ambar', fallido: 'insignia-rojo' };

function useSeccionesYPeriodos() {
  const { pedir } = useSesion();
  const { anios, anio, setAnio } = useAnioEscolar();
  const secciones = useCarga(() => (anio ? pedir<{ data: Seccion[] }>(`/asistencia/secciones?anio_escolar_id=${anio}`) : Promise.resolve({ data: [] })), [anio]);
  const periodos = useCarga(() => (anio ? pedir<{ periodos: Periodo[] }>(`/notas/cargas?anio_escolar_id=${anio}`) : Promise.resolve({ periodos: [] })), [anio]);
  return { anios, anio, setAnio, secciones: secciones.datos?.data ?? [], periodos: periodos.datos?.periodos ?? [] };
}

/** Sigue un proceso en cola hasta que termine. */
function useProceso(id: number | null) {
  const { pedir } = useSesion();
  const [p, setP] = useState<Proceso | null>(null);
  useEffect(() => {
    if (!id) return;
    const control: { vivo: boolean; t?: ReturnType<typeof setTimeout> } = { vivo: true };
    const consultar = async () => {
      const r = await pedir<Proceso>(`/siagie/procesos/${id}`).catch(() => null);
      if (!control.vivo || !r) return;
      setP(r);
      if (['pendiente', 'procesando'].includes(r.estado)) control.t = setTimeout(consultar, 2000);
    };
    consultar();
    return () => { control.vivo = false; clearTimeout(control.t); };
  }, [id, pedir]);
  return p;
}

function Nomina() {
  const { pedir } = useSesion();
  const s = useSeccionesYPeriodos();
  const accion = useAccion();
  const [seccion, setSeccion] = useState<number | ''>('');
  const [archivo, setArchivo] = useState<File | null>(null);
  const [procesoId, setProcesoId] = useState<number | null>(null);
  const p = useProceso(procesoId);
  const noFiguran = (p?.advertencias as { no_figuran?: { id: number; nombre: string; codigo_estudiante_siagie: string | null }[] } | null)?.no_figuran ?? [];
  return (
    <div className="tarjeta">
      <p className="ayuda">Sube la nómina tal como la descargas del SIAGIE (.xlsx o .xls, hasta 10 MB). Se procesa en segundo plano y verás los errores por fila.</p>
      <div className="filtros" style={{ alignItems: 'end' }}>
        {s.anios.length > 0 && <SelectorAnio anios={s.anios} anio={s.anio} alCambiar={s.setAnio} />}
        <Campo etiqueta="Sección"><select value={seccion} onChange={(e) => setSeccion(Number(e.target.value) || '')}><option value="">Elige</option>{s.secciones.map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}</select></Campo>
        <label className="boton boton-borde">{archivo ? archivo.name : 'Elegir archivo'}<input type="file" hidden accept=".xlsx,.xls" onChange={(e) => setArchivo(e.target.files?.[0] ?? null)} /></label>
        <button type="button" className="boton" disabled={!seccion || !archivo || accion.enviando} onClick={() => accion.ejecutar(async () => {
          const cuerpo = new FormData();
          cuerpo.append('archivo', archivo!);
          cuerpo.append('seccion_id', String(seccion));
          setProcesoId((await pedir<{ id: number }>('/siagie/nominas', { metodo: 'POST', cuerpo })).id);
        })}>Importar</button>
      </div>
      <Aviso tipo="error">{accion.error}</Aviso>
      {p && (
        <>
          <p><span className={`insignia ${ESTADO[p.estado]}`}>{p.estado.replace('_', ' ')}</span> {p.total_filas} filas · {p.creados} nuevos · {p.actualizados} actualizados · {p.con_error} con error</p>
          {p.errores && p.errores.length > 0 && <ul>{p.errores.map((e) => <li key={e.fila}>Fila {e.fila}: {e.mensaje}</li>)}</ul>}
          {noFiguran.length > 0 && (
            <Aviso tipo="ambar">
              Matriculados en Edunova que no figuran en la nómina (revisa si se retiraron o trasladaron):
              <ul>{noFiguran.map((a) => <li key={a.id}><a href={`#/alumnos/${a.id}`}>{a.nombre}</a>{a.codigo_estudiante_siagie ? ` · ${a.codigo_estudiante_siagie}` : ''}</li>)}</ul>
            </Aviso>
          )}
        </>
      )}
    </div>
  );
}

function Plantillas() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const s = useSeccionesYPeriodos();
  const accion = useAccion();
  const [seccion, setSeccion] = useState<number | ''>('');
  const [periodo, setPeriodo] = useState<number | ''>('');
  const [errores, setErrores] = useState<ErrorNota[] | null>(null);
  const [plantilla, setPlantilla] = useState<File | null>(null);
  const [lote, setLote] = useState<File[]>([]);
  const [resultado, setResultado] = useState<Proceso | null>(null);
  const [loteId, setLoteId] = useState<number | null>(null);
  const procesoLote = useProceso(loteId);
  const descargar = (id: number) => accion.ejecutar(async () => { window.open((await pedir<{ url: string }>(`/siagie/procesos/${id}/resultado`)).url, '_blank', 'noopener'); });
  const advertencias = (x: Proceso | null) => (Array.isArray(x?.advertencias) ? (x!.advertencias as string[]) : []);
  return (
    <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
      <div className="tarjeta">
        <h2>Validar y llenar la plantilla de una sección</h2>
        <div className="filtros" style={{ alignItems: 'end' }}>
          {s.anios.length > 0 && <SelectorAnio anios={s.anios} anio={s.anio} alCambiar={s.setAnio} />}
          <Campo etiqueta="Sección"><select value={seccion} onChange={(e) => { setSeccion(Number(e.target.value) || ''); setErrores(null); }}><option value="">Elige</option>{s.secciones.map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}</select></Campo>
          <Campo etiqueta="Periodo"><select value={periodo} onChange={(e) => { setPeriodo(Number(e.target.value) || ''); setErrores(null); }}><option value="">Elige</option>{s.periodos.map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}</select></Campo>
          {puede(colegio, 'sia.plantillas.validar') && <button type="button" className="boton boton-borde" disabled={!seccion || !periodo} onClick={() => accion.ejecutar(async () => {
            setErrores((await pedir<{ errores: ErrorNota[] }>(`/siagie/validacion?seccion_id=${seccion}&periodo_id=${periodo}`)).errores);
          })}>Validar notas</button>}
        </div>
        {errores && (errores.length === 0 ? <Aviso tipo="ok">Las notas están completas: puedes generar la plantilla.</Aviso> : (
          <Aviso tipo="ambar">
            {errores.length} problemas. Corrígelos antes de generar:
            <ul>{errores.slice(0, 60).map((e, i) => <li key={i}>{e.alumno}: {e.mensaje} <a href={e.enlace}>Corregir</a></li>)}</ul>
          </Aviso>
        ))}
        {puede(colegio, 'sia.plantillas.generar') && (
          <div className="filtros" style={{ alignItems: 'end' }}>
            <label className="boton boton-borde">{plantilla ? plantilla.name : 'Plantilla vacía del SIAGIE'}<input type="file" hidden accept=".xlsx,.xls" onChange={(e) => setPlantilla(e.target.files?.[0] ?? null)} /></label>
            <button type="button" className="boton" disabled={!seccion || !periodo || !plantilla || accion.enviando} onClick={() => accion.ejecutar(async () => {
              const cuerpo = new FormData();
              cuerpo.append('plantilla', plantilla!);
              cuerpo.append('seccion_id', String(seccion));
              cuerpo.append('periodo_id', String(periodo));
              setResultado(await pedir<Proceso>('/siagie/plantillas', { metodo: 'POST', cuerpo }));
            })}>Llenar plantilla</button>
          </div>
        )}
        <Aviso tipo="error">{accion.error}</Aviso>
        {resultado && (
          <Aviso tipo="ok">
            Plantilla lista: {resultado.actualizados} notas de {resultado.total_filas} alumnos. <button type="button" className="boton boton-chico" onClick={() => descargar(resultado.id)}>Descargar</button>
            {advertencias(resultado).length > 0 && <ul>{advertencias(resultado).map((a, i) => <li key={i}>{a}</li>)}</ul>}
          </Aviso>
        )}
      </div>
      {puede(colegio, 'sia.plantillas.generar') && (
        <div className="tarjeta">
          <h2>Todas las secciones a la vez</h2>
          <p className="ayuda">Sube las plantillas de todas las secciones del periodo: se reconoce la sección de cada una por los códigos de sus alumnos y recibes un ZIP con un resumen de advertencias.</p>
          <div className="filtros" style={{ alignItems: 'end' }}>
            <label className="boton boton-borde">{lote.length ? `${lote.length} plantillas` : 'Elegir plantillas'}<input type="file" hidden multiple accept=".xlsx,.xls" onChange={(e) => setLote(Array.from(e.target.files ?? []))} /></label>
            <button type="button" className="boton" disabled={!periodo || !lote.length || accion.enviando} onClick={() => accion.ejecutar(async () => {
              const cuerpo = new FormData();
              lote.forEach((f) => cuerpo.append('plantillas[]', f));
              cuerpo.append('periodo_id', String(periodo));
              setLoteId((await pedir<{ id: number }>('/siagie/plantillas/lote', { metodo: 'POST', cuerpo })).id);
            })}>Generar ZIP</button>
          </div>
          {!periodo && <p className="ayuda">Elige el periodo arriba.</p>}
          {procesoLote && (
            <>
              <p><span className={`insignia ${ESTADO[procesoLote.estado]}`}>{procesoLote.estado.replace('_', ' ')}</span> {procesoLote.total_filas} plantillas generadas
                {procesoLote.archivo_resultado_id && <> · <button type="button" className="boton boton-chico" onClick={() => descargar(procesoLote.id)}>Descargar ZIP</button></>}</p>
              {advertencias(procesoLote).length > 0 && <ul>{advertencias(procesoLote).map((a, i) => <li key={i}>{a}</li>)}</ul>}
            </>
          )}
        </div>
      )}
    </div>
  );
}

type AreaMapeo = { id: number; nombre: string; competencias: { id: number; numero: number; nombre: string }[] };
type HojaMapeo = { hoja: string; area_id: number; fila_inicio: number; col_codigo: string; competencias: Record<string, string> };

function Mapeo() {
  const { pedir } = useSesion();
  const grados = useCarga(() => pedir<{ grados: { id: number; nombre: string }[] }>('/plan-estudios').catch(() => ({ grados: [] })));
  const [grado, setGrado] = useState<number | ''>('');
  const datos = useCarga(() => (grado ? pedir<{ mapeo: HojaMapeo[] | null; areas: AreaMapeo[] }>(`/siagie/mapeos/${grado}`) : Promise.resolve(null)), [grado]);
  const [hojas, setHojas] = useState<HojaMapeo[] | null>(null);
  const accion = useAccion();
  const lista = hojas ?? datos.datos?.mapeo ?? [];
  const cambiar = (i: number, c: Partial<HojaMapeo>) => setHojas(lista.map((h, j) => (j === i ? { ...h, ...c } : h)));
  return (
    <div className="tarjeta">
      <p className="ayuda">Úsalo solo si al llenar la plantilla se indica que no se reconocieron columnas. Indica, por hoja, el área, la fila donde empiezan los alumnos y la letra de cada columna. Se guarda por grado.</p>
      <Campo etiqueta="Grado"><select value={grado} onChange={(e) => { setGrado(Number(e.target.value) || ''); setHojas(null); }}><option value="">Elige</option>{grados.datos?.grados.map((g) => <option key={g.id} value={g.id}>{g.nombre}</option>)}</select></Campo>
      {datos.datos && (
        <>
          {lista.map((h, i) => {
            const area = datos.datos!.areas.find((a) => a.id === h.area_id);
            return (
              <div key={i} className="tarjeta" style={{ marginTop: 10 }}>
                <div className="campos">
                  <Campo etiqueta="Nombre de la hoja"><input value={h.hoja} onChange={(e) => cambiar(i, { hoja: e.target.value })} /></Campo>
                  <Campo etiqueta="Área"><select value={h.area_id} onChange={(e) => cambiar(i, { area_id: Number(e.target.value), competencias: {} })}>{datos.datos!.areas.map((a) => <option key={a.id} value={a.id}>{a.nombre}</option>)}</select></Campo>
                  <Campo etiqueta="Fila del primer alumno"><input type="number" min={1} value={h.fila_inicio} onChange={(e) => cambiar(i, { fila_inicio: Number(e.target.value) })} /></Campo>
                  <Campo etiqueta="Columna del código"><input maxLength={3} value={h.col_codigo} onChange={(e) => cambiar(i, { col_codigo: e.target.value.toUpperCase() })} /></Campo>
                  {area?.competencias.map((k) => (
                    <Campo key={k.id} etiqueta={`C${k.numero}`}><input maxLength={3} value={h.competencias[k.id] ?? ''} onChange={(e) => cambiar(i, { competencias: { ...h.competencias, [k.id]: e.target.value.toUpperCase() } })} /></Campo>
                  ))}
                </div>
              </div>
            );
          })}
          <div className="acciones">
            <button type="button" className="boton boton-borde" disabled={!datos.datos.areas.length} onClick={() => setHojas([...lista, { hoja: `Hoja${lista.length + 1}`, area_id: datos.datos!.areas[0].id, fila_inicio: 11, col_codigo: 'B', competencias: {} }])}>+ Hoja</button>
            <button type="button" className="boton" disabled={!lista.length || accion.enviando} onClick={() => accion.ejecutar(async () => {
              await pedir(`/siagie/mapeos/${grado}`, { metodo: 'PUT', cuerpo: { hojas: lista } });
              setHojas(null);
              datos.recargar();
            }, 'Mapeo guardado: se usará en las próximas plantillas de este grado.')}>Guardar mapeo</button>
          </div>
          <Aviso tipo="error">{accion.error}</Aviso>
          <Aviso tipo="ok">{accion.ok}</Aviso>
        </>
      )}
    </div>
  );
}

function Historial() {
  const { pedir } = useSesion();
  const { datos, error } = useCarga(() => pedir<{ data: Proceso[] }>('/siagie/procesos'));
  const accion = useAccion();
  const abrir = (id: number, cual: string) => accion.ejecutar(async () => { window.open((await pedir<{ url: string }>(`/siagie/procesos/${id}/${cual}`)).url, '_blank', 'noopener'); });
  return (
    <div className="tarjeta">
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <div className="tabla-caja">
        <table className="tabla">
          <thead><tr><th>Fecha</th><th>Proceso</th><th>Sección / periodo</th><th>Estado</th><th>Resultado</th><th /></tr></thead>
          <tbody>
            {datos?.data.map((p) => (
              <tr key={p.id}>
                <td>{fechaHora(p.created_at)}<br /><small className="ayuda">{p.usuario}</small></td>
                <td>{p.tipo === 'nomina' ? 'Nómina' : 'Plantilla de notas'}<br /><small className="ayuda">{p.archivo}</small></td>
                <td>{p.seccion ?? 'Varias'}{p.periodo ? ` · ${p.periodo}` : ''}</td>
                <td><span className={`insignia ${ESTADO[p.estado]}`}>{p.estado.replace('_', ' ')}</span></td>
                <td>{p.tipo === 'nomina' ? `${p.creados} nuevos · ${p.actualizados} act. · ${p.con_error} errores` : `${p.total_filas} ${p.seccion ? 'alumnos' : 'plantillas'}`}</td>
                <td>
                  <button type="button" className="boton boton-chico boton-borde" onClick={() => abrir(p.id, 'original')}>Original</button>{' '}
                  {p.archivo_resultado_id && <button type="button" className="boton boton-chico boton-borde" onClick={() => abrir(p.id, 'resultado')}>Resultado</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {datos?.data.length === 0 && <p className="vacio">Aún no hay procesos.</p>}
      </div>
    </div>
  );
}

/** Sprint 20 · SIA-01 a 05: nómina, validación, plantillas, mapeo e historial del SIAGIE. */
export function Siagie() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const opciones: ['nomina' | 'plantillas' | 'mapeo' | 'historial', string][] = [
    ...(puede(colegio, 'sia.nomina.importar') ? [['nomina', 'Nómina'] as ['nomina', string]] : []),
    ['plantillas', 'Notas al SIAGIE'],
    ...(puede(colegio, 'sia.plantillas.validar') ? [['mapeo', 'Mapeo de plantillas'] as ['mapeo', string]] : []),
    ['historial', 'Historial'],
  ];
  const [vista, setVista] = useState(opciones[0][0]);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Matrícula`} titulo="SIAGIE" descripcion="El SIAGIE no tiene API: se intercambian Excel. La plantilla original se conserva y el resultado es un archivo nuevo en el mismo formato." />
      <Pestanas opciones={opciones} actual={vista} alCambiar={setVista} />
      <div style={{ marginTop: 12 }}>
        {vista === 'nomina' && <Nomina />}
        {vista === 'plantillas' && <Plantillas />}
        {vista === 'mapeo' && <Mapeo />}
        {vista === 'historial' && <Historial />}
      </div>
    </>
  );
}
