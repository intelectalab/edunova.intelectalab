import { useState } from 'react';
import { apiArchivo, guardarArchivo } from '../api/cliente';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Pestanas, Titulo } from '../panel/ui';
import { mensajeError, useAccion, useCarga } from '../panel/utilidades';
import { TURNOS, useAnioEscolar } from './anios';
import { SelectorAnio } from './SelectorAnio';

type AreaCarga = {
  area_curricular_id: number;
  area: string;
  horas_plan: number | null;
  carga_id: number | null;
  personal_id: number | null;
  docente: string | null;
  horas_semanales: number | null;
  curso_aula: boolean;
  curso_moodle: string | null;
};
type SeccionCarga = { seccion_id: number; seccion: string; areas: AreaCarga[] };
type Cargas = { secciones: SeccionCarga[]; docentes: { id: number; nombre: string; especialidad: string | null }[] };
type Bloque = { id?: number; turno?: string; hora_inicio: string; hora_fin: string; es_recreo: boolean };
type Celda = { id: number; dia_semana: number; bloque_horario_id: number; carga_docente_id: number; ambiente_id: number | null; area: string; docente: string; seccion: string; ambiente: string | null };
type Vista = {
  titulo: string;
  bloques: Required<Bloque>[];
  dias: Record<string, string>;
  celdas: Celda[];
  cargas: { id: number; area: string; docente: string }[];
  ambientes: { id: number; nombre: string }[];
};

function CargaDocente({ anio, editable }: { anio: number; editable: boolean }) {
  const { pedir } = useSesion();
  const { datos, setDatos, error } = useCarga(() => pedir<Cargas>(`/cargas?anio_escolar_id=${anio}`), [anio]);
  const accion = useAccion();

  async function asignar(s: SeccionCarga, a: AreaCarga, personal: number | null, horas: number | null) {
    await accion.ejecutar(async () => {
      const r = await pedir<{ carga_id: number | null; aviso: string | null }>('/cargas', {
        metodo: 'PUT',
        cuerpo: { seccion_id: s.seccion_id, area_curricular_id: a.area_curricular_id, personal_id: personal, horas_semanales: horas },
      });
      if (r.aviso) accion.setOk(r.aviso);
      setDatos(await pedir<Cargas>(`/cargas?anio_escolar_id=${anio}`));
    });
  }

  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ambar">{accion.ok}</Aviso>
      {datos?.secciones.length === 0 && <p className="vacio">No hay secciones en este año.</p>}
      <div className="rejilla" style={{ marginTop: 12 }}>
        {datos?.secciones.map((s) => (
          <div key={s.seccion_id} className="tarjeta">
            <h2>{s.seccion}</h2>
            {s.areas.length === 0 ? (
              <p className="ayuda">El grado aún no tiene plan de estudios en este año.</p>
            ) : (
              <div className="tabla-caja" style={{ marginTop: 8 }}>
                <table className="tabla">
                  <thead>
                    <tr>
                      <th>Área</th>
                      <th>Docente</th>
                      <th>Horas</th>
                    </tr>
                  </thead>
                  <tbody>
                    {s.areas.map((a) => (
                      <tr key={a.area_curricular_id}>
                        <td>
                          {a.area}
                          {a.curso_aula && <span className="insignia insignia-azul" style={{ marginLeft: 6 }}>Aula</span>}
                          {a.curso_moodle && <span className="insignia" style={{ marginLeft: 6 }}>Moodle {a.curso_moodle === 'pendiente' ? '(por crear)' : ''}</span>}
                        </td>
                        <td style={{ minWidth: 180 }}>
                          <select
                            className="control"
                            aria-label={`Docente de ${a.area} en ${s.seccion}`}
                            disabled={!editable || accion.enviando}
                            value={a.personal_id ?? ''}
                            onChange={(e) => void asignar(s, a, e.target.value ? Number(e.target.value) : null, a.horas_semanales ?? a.horas_plan)}
                          >
                            <option value="">Sin docente</option>
                            {datos.docentes.map((d) => (
                              <option key={d.id} value={d.id}>
                                {d.nombre}
                                {d.especialidad ? ` · ${d.especialidad}` : ''}
                              </option>
                            ))}
                          </select>
                        </td>
                        <td style={{ width: 80 }}>
                          <input
                            className="control"
                            aria-label={`Horas de ${a.area} en ${s.seccion}`}
                            inputMode="numeric"
                            maxLength={2}
                            disabled={!editable || !a.personal_id}
                            defaultValue={a.horas_semanales ?? a.horas_plan ?? ''}
                            onBlur={(e) => {
                              const h = e.target.value ? Number(e.target.value) : null;
                              if (a.personal_id && h !== a.horas_semanales) void asignar(s, a, a.personal_id, h);
                            }}
                          />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        ))}
      </div>
    </>
  );
}

function Horario({ anio, editable }: { anio: number; editable: boolean }) {
  const { pedir, token } = useSesion();
  const cargas = useCarga(() => pedir<Cargas>(`/cargas?anio_escolar_id=${anio}`), [anio]);
  const [modo, setModo] = useState<'seccion' | 'docente'>('seccion');
  const [eleccion, setEleccion] = useState<number | null>(null);
  const opciones = modo === 'seccion' ? (cargas.datos?.secciones.map((s) => ({ id: s.seccion_id, nombre: s.seccion })) ?? []) : (cargas.datos?.docentes ?? []);
  const elegido = eleccion ?? opciones[0]?.id ?? null;
  const consulta = elegido ? `anio_escolar_id=${anio}&${modo === 'seccion' ? 'seccion_id' : 'personal_id'}=${elegido}` : null;
  const { datos, setDatos, error } = useCarga(() => (consulta ? pedir<Vista>(`/horarios?${consulta}`) : Promise.resolve(null)), [consulta]);
  const accion = useAccion();
  const celdas = new Map(datos?.celdas.map((c) => [`${c.dia_semana}-${c.bloque_horario_id}`, c]) ?? []);
  const editableAqui = editable && modo === 'seccion';

  async function asignar(dia: number, bloque: number, carga: number | null, ambiente: number | null) {
    await accion.ejecutar(async () => {
      await pedir('/horarios/celda', { metodo: 'PUT', cuerpo: { seccion_id: elegido, dia_semana: dia, bloque_horario_id: bloque, carga_docente_id: carga, ambiente_id: ambiente } });
      setDatos(await pedir<Vista>(`/horarios?${consulta}`));
    });
  }

  async function pdf() {
    try {
      guardarArchivo(await apiArchivo(`/horarios/pdf?${consulta}`, token), 'horario.pdf');
    } catch (e) {
      accion.setError(mensajeError(e));
    }
  }

  return (
    <>
      <div className="tarjeta" style={{ marginBottom: 18 }}>
        <div className="filtros" style={{ marginBottom: 0 }}>
          <Campo etiqueta="Ver horario de">
            <select
              value={modo}
              onChange={(e) => {
                setModo(e.target.value as 'seccion' | 'docente');
                setEleccion(null);
              }}
            >
              <option value="seccion">Una sección</option>
              <option value="docente">Un docente</option>
            </select>
          </Campo>
          <Campo etiqueta={modo === 'seccion' ? 'Sección' : 'Docente'}>
            <select value={elegido ?? ''} onChange={(e) => setEleccion(Number(e.target.value))}>
              {opciones.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.nombre}
                </option>
              ))}
            </select>
          </Campo>
          {consulta && (
            <button type="button" className="boton boton-claro" onClick={pdf}>
              Exportar PDF
            </button>
          )}
        </div>
      </div>
      <Aviso tipo="error">{error ?? cargas.error ?? accion.error}</Aviso>
      {datos && datos.bloques.length === 0 && <p className="vacio">Primero define los bloques de hora del turno (pestaña «Bloques de hora»).</p>}
      {datos && datos.bloques.length > 0 && (
        <div className="tarjeta">
          <h2>{datos.titulo}</h2>
          <div className="tabla-caja" style={{ marginTop: 10 }}>
            <table className="tabla horario">
              <thead>
                <tr>
                  <th>Hora</th>
                  {Object.values(datos.dias).map((d) => (
                    <th key={d}>{d}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {datos.bloques.map((b) => (
                  <tr key={b.id}>
                    <th style={{ whiteSpace: 'nowrap' }}>
                      {b.hora_inicio} – {b.hora_fin}
                    </th>
                    {Object.keys(datos.dias).map((d) => {
                      const c = celdas.get(`${d}-${b.id}`);
                      if (b.es_recreo) {
                        return (
                          <td key={d} className="ayuda" style={{ textAlign: 'center', background: 'var(--en-suave)' }}>
                            Recreo
                          </td>
                        );
                      }
                      if (!editableAqui) {
                        return (
                          <td key={d}>
                            {c && (
                              <>
                                <b>{c.area}</b>
                                <br />
                                <small className="ayuda">
                                  {modo === 'seccion' ? c.docente : c.seccion}
                                  {c.ambiente ? ` · ${c.ambiente}` : ''}
                                </small>
                              </>
                            )}
                          </td>
                        );
                      }
                      return (
                        <td key={d} style={{ minWidth: 150 }}>
                          <select
                            className="control"
                            aria-label={`${datos.dias[d]} ${b.hora_inicio}`}
                            value={c?.carga_docente_id ?? ''}
                            disabled={accion.enviando}
                            onChange={(e) => void asignar(Number(d), b.id, e.target.value ? Number(e.target.value) : null, c?.ambiente_id ?? null)}
                          >
                            <option value="">—</option>
                            {datos.cargas.map((o) => (
                              <option key={o.id} value={o.id}>
                                {o.area} · {o.docente}
                              </option>
                            ))}
                          </select>
                          {c && (
                            <select
                              className="control"
                              style={{ marginTop: 4, height: 30, fontSize: 13 }}
                              aria-label={`Ambiente del ${datos.dias[d]} ${b.hora_inicio}`}
                              value={c.ambiente_id ?? ''}
                              disabled={accion.enviando}
                              onChange={(e) => void asignar(Number(d), b.id, c.carga_docente_id, e.target.value ? Number(e.target.value) : null)}
                            >
                              <option value="">Aula de la sección</option>
                              {datos.ambientes.map((a) => (
                                <option key={a.id} value={a.id}>
                                  {a.nombre}
                                </option>
                              ))}
                            </select>
                          )}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {editableAqui && datos.cargas.length === 0 && <Aviso tipo="ambar">Esta sección aún no tiene docentes asignados (pestaña «Carga docente»).</Aviso>}
        </div>
      )}
    </>
  );
}

function Bloques({ editable }: { editable: boolean }) {
  const { pedir } = useSesion();
  const { datos, setDatos, error } = useCarga(() => pedir<{ data: Required<Bloque>[]; sugeridos: Record<string, [string, string, boolean?][]> }>('/bloques-horarios'));
  const accion = useAccion();
  const [turno, setTurno] = useState('manana');
  const [filas, setFilas] = useState<Record<string, Bloque[]>>({});
  const actuales = filas[turno] ?? datos?.data.filter((b) => b.turno === turno) ?? [];
  const cambiar = (i: number, k: keyof Bloque, v: string | boolean) => setFilas({ ...filas, [turno]: actuales.map((b, j) => (j === i ? { ...b, [k]: v } : b)) });

  return (
    <div className="tarjeta" style={{ maxWidth: 720 }}>
      <div className="filtros">
        <Campo etiqueta="Turno">
          <select value={turno} onChange={(e) => setTurno(e.target.value)}>
            {Object.entries(TURNOS).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </Campo>
        {editable && datos && (
          <button
            type="button"
            className="boton boton-claro"
            onClick={() => setFilas({ ...filas, [turno]: datos.sugeridos[turno].map(([i, f, r]) => ({ hora_inicio: i, hora_fin: f, es_recreo: Boolean(r) })) })}
          >
            Usar bloques sugeridos
          </button>
        )}
      </div>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <div className="tabla-caja">
        <table className="tabla">
          <thead>
            <tr>
              <th>#</th>
              <th>Inicio</th>
              <th>Fin</th>
              <th>Recreo</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {actuales.map((b, i) => (
              <tr key={i}>
                <td>{i + 1}</td>
                <td>
                  <input className="control" type="time" aria-label={`Inicio del bloque ${i + 1}`} value={b.hora_inicio} disabled={!editable} onChange={(e) => cambiar(i, 'hora_inicio', e.target.value)} />
                </td>
                <td>
                  <input className="control" type="time" aria-label={`Fin del bloque ${i + 1}`} value={b.hora_fin} disabled={!editable} onChange={(e) => cambiar(i, 'hora_fin', e.target.value)} />
                </td>
                <td>
                  <input type="checkbox" aria-label={`Bloque ${i + 1} es recreo`} checked={b.es_recreo} disabled={!editable} onChange={(e) => cambiar(i, 'es_recreo', e.target.checked)} />
                </td>
                <td style={{ textAlign: 'right' }}>
                  {editable && (
                    <button type="button" className="boton boton-chico boton-borde" onClick={() => setFilas({ ...filas, [turno]: actuales.filter((_, j) => j !== i) })}>
                      Quitar
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {actuales.length === 0 && <p className="vacio">Este turno aún no tiene bloques.</p>}
      </div>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {editable && (
        <div className="acciones">
          <button
            type="button"
            className="boton boton-claro"
            onClick={() => {
              const ultimo = actuales[actuales.length - 1];
              setFilas({ ...filas, [turno]: [...actuales, { hora_inicio: ultimo?.hora_fin ?? '08:00', hora_fin: '', es_recreo: false }] });
            }}
          >
            + Bloque
          </button>
          <button
            type="button"
            className="boton"
            disabled={accion.enviando || actuales.length === 0}
            onClick={() =>
              accion.ejecutar(async () => {
                const r = await pedir<{ data: Required<Bloque>[] }>('/bloques-horarios', { metodo: 'PUT', cuerpo: { turno, bloques: actuales } });
                setDatos({ ...datos!, data: [...datos!.data.filter((b) => b.turno !== turno), ...r.data] });
                const resto = { ...filas };
                delete resto[turno];
                setFilas(resto);
              }, 'Bloques guardados.')
            }
          >
            Guardar bloques
          </button>
        </div>
      )}
    </div>
  );
}

/** EDUN-48 / EDUN-49 · ADM-05 Carga docente y horario semanal sin cruces. */
export function Horarios() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const editable = puede(colegio, 'adm.horarios.editar');
  const { anios, anio, setAnio, error } = useAnioEscolar();
  const [pestana, setPestana] = useState<'carga' | 'horario' | 'bloques'>('carga');

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Académico`} titulo="Carga docente y horarios" descripcion="Docente por área y sección, y horario semanal sin cruces de docente ni de aula.">
        {anios.length > 0 && pestana !== 'bloques' && <SelectorAnio anios={anios} anio={anio} alCambiar={setAnio} />}
      </Titulo>
      <Pestanas
        opciones={[
          ['carga', 'Carga docente'],
          ['horario', 'Horario'],
          ['bloques', 'Bloques de hora'],
        ]}
        actual={pestana}
        alCambiar={setPestana}
      />
      <Aviso tipo="error">{error}</Aviso>
      {pestana === 'bloques' && <Bloques editable={editable} />}
      {anio !== null && pestana === 'carga' && <CargaDocente key={anio} anio={anio} editable={editable} />}
      {anio !== null && pestana === 'horario' && <Horario key={anio} anio={anio} editable={editable} />}
    </>
  );
}
