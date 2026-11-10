import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Pestanas, Titulo } from '../panel/ui';
import { useAccion, useCarga } from '../panel/utilidades';
import { TURNOS as TURNO, useAnioEscolar, type AnioResumen } from './anios';
import { SelectorAnio } from './SelectorAnio';

type AreaCat = { id: number; codigo: string; nombre: string; es_transversal: boolean };
type AreaPlan = { area_curricular_id: number; nombre: string; horas_semanales: number | null; es_transversal: boolean };
type Grado = { id: number; nivel: string; numero: number; nombre: string; escala: string; areas: AreaPlan[] };
type Plan = { grados: Grado[]; catalogo: Record<string, AreaCat[]> };
type Seccion = {
  id: number;
  grado_id: number;
  grado: string;
  nombre: string;
  turno: string;
  ambiente_id: number | null;
  ambiente: string | null;
  tutor_personal_id: number | null;
  tutor: string | null;
  vacantes: number | null;
  matriculados: number;
};
type Opciones = { grados: { id: number; nombre: string }[]; ambientes: { id: number; nombre: string }[]; personal: { id: number; nombre: string; cargo: string }[] };

const NIVEL: Record<string, string> = { inicial: 'Inicial', primaria: 'Primaria', secundaria: 'Secundaria' };


function Copiar({ anios, anio, ruta, que, alTerminar }: { anios: AnioResumen[]; anio: number; ruta: string; que: string; alTerminar: () => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const otros = anios.filter((a) => a.id !== anio);
  const [desde, setDesde] = useState<number | null>(otros[0]?.id ?? null);
  if (otros.length === 0) return null;
  return (
    <div className="filtros" style={{ marginBottom: 0 }}>
      <Campo etiqueta={`Copiar ${que} de`}>
        <select value={desde ?? ''} onChange={(e) => setDesde(Number(e.target.value))}>
          {otros.map((a) => (
            <option key={a.id} value={a.id}>
              {a.anio}
            </option>
          ))}
        </select>
      </Campo>
      <button
        type="button"
        className="boton boton-claro"
        disabled={accion.enviando || !desde}
        onClick={() =>
          accion.ejecutar(async () => {
            const r = await pedir<{ grados?: number; creadas?: number; omitidos?: number }>(ruta, { metodo: 'POST', cuerpo: { desde_anio_id: desde, hacia_anio_id: anio } });
            accion.setOk(r.creadas !== undefined ? `Se copiaron ${r.creadas} secciones.` : `Se copió el plan de ${r.grados} grados${r.omitidos ? ` (${r.omitidos} ya tenían plan)` : ''}.`);
            alTerminar();
          })
        }
      >
        Copiar
      </button>
      <Aviso tipo="error">{accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
    </div>
  );
}

function EditarGrado({ grado, catalogo, anio, alGuardar }: { grado: Grado; catalogo: AreaCat[]; anio: number; alGuardar: (p: Grado[]) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [escala, setEscala] = useState(grado.escala);
  const [areas, setAreas] = useState<Record<number, string>>(() => Object.fromEntries(grado.areas.map((a) => [a.area_curricular_id, a.horas_semanales ? String(a.horas_semanales) : ''])));

  async function guardar(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => {
      const cuerpo = {
        anio_escolar_id: anio,
        escala,
        areas: catalogo.filter((a) => a.id in areas).map((a) => ({ area_curricular_id: a.id, horas_semanales: areas[a.id] ? Number(areas[a.id]) : null })),
      };
      alGuardar((await pedir<{ grados: Grado[] }>(`/plan-estudios/grados/${grado.id}`, { metodo: 'PUT', cuerpo })).grados);
    });
  }

  return (
    <form onSubmit={guardar} noValidate>
      <p className="ayuda">Marca las áreas del Currículo Nacional que se califican en el grado y sus horas por semana.</p>
      <Campo etiqueta="Escala de calificación">
        <select value={escala} onChange={(e) => setEscala(e.target.value)}>
          <option value="literal">Literal (AD, A, B, C)</option>
          <option value="vigesimal">Vigesimal (0 a 20)</option>
        </select>
      </Campo>
      <div className="tabla-caja" style={{ marginTop: 12 }}>
        <table className="tabla">
          <tbody>
            {catalogo.map((a) => (
              <tr key={a.id}>
                <td style={{ width: 36 }}>
                  <input
                    type="checkbox"
                    aria-label={a.nombre}
                    checked={a.id in areas}
                    onChange={(e) => {
                      const nuevo = { ...areas };
                      if (e.target.checked) nuevo[a.id] = '';
                      else delete nuevo[a.id];
                      setAreas(nuevo);
                    }}
                  />
                </td>
                <td>
                  {a.nombre} {a.es_transversal && <span className="insignia">Transversal</span>}
                </td>
                <td style={{ width: 120 }}>
                  <input
                    className="control"
                    aria-label={`Horas de ${a.nombre}`}
                    placeholder="Horas"
                    inputMode="numeric"
                    maxLength={2}
                    disabled={!(a.id in areas)}
                    value={areas[a.id] ?? ''}
                    onChange={(e) => setAreas({ ...areas, [a.id]: e.target.value.replace(/\D/g, '') })}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="submit" className="boton" disabled={accion.enviando}>
          {accion.enviando ? 'Guardando…' : 'Guardar plan del grado'}
        </button>
      </div>
    </form>
  );
}

function PlanPorGrado({ anio, anios, editable }: { anio: number; anios: AnioResumen[]; editable: boolean }) {
  const { pedir } = useSesion();
  const { datos, setDatos, error, recargar } = useCarga(() => pedir<Plan>(`/plan-estudios?anio_escolar_id=${anio}`), [anio]);
  const accion = useAccion();
  const [editando, setEditando] = useState<Grado | null>(null);
  const niveles = [...new Set(datos?.grados.map((g) => g.nivel) ?? [])];

  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {editable && (
        <div className="tarjeta" style={{ marginBottom: 18, display: 'flex', flexWrap: 'wrap', gap: 16, alignItems: 'flex-end', justifyContent: 'space-between' }}>
          <Copiar anios={anios} anio={anio} ruta="/plan-estudios/copiar" que="el plan" alTerminar={recargar} />
          <button
            type="button"
            className="boton boton-borde"
            disabled={accion.enviando}
            onClick={() =>
              accion.ejecutar(async () => {
                const r = await pedir<{ creados: number }>('/plan-estudios/grados', { metodo: 'POST' });
                accion.setOk(r.creados ? `Se crearon ${r.creados} grados.` : 'Ya están todos los grados de tus niveles.');
                recargar();
              })
            }
          >
            Crear grados de mis niveles
          </button>
        </div>
      )}
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos && datos.grados.length === 0 && <p className="vacio">Aún no hay grados. Usa «Crear grados de mis niveles».</p>}
      {niveles.map((n) => (
        <div key={n} className="tarjeta" style={{ marginBottom: 18 }}>
          <h2>{NIVEL[n] ?? n}</h2>
          <div className="tabla-caja" style={{ marginTop: 10 }}>
            <table className="tabla">
              <thead>
                <tr>
                  <th>Grado</th>
                  <th>Escala</th>
                  <th>Áreas</th>
                  <th>Horas</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {datos!.grados
                  .filter((g) => g.nivel === n)
                  .map((g) => (
                    <tr key={g.id}>
                      <td>
                        <b>{g.nombre}</b>
                      </td>
                      <td>{g.escala === 'literal' ? 'Literal' : 'Vigesimal'}</td>
                      <td>{g.areas.length ? g.areas.map((a) => a.nombre).join(', ') : <span className="insignia insignia-ambar">Sin plan</span>}</td>
                      <td>{g.areas.reduce((s, a) => s + (a.horas_semanales ?? 0), 0) || '—'}</td>
                      <td style={{ textAlign: 'right' }}>
                        {editable && (
                          <button type="button" className="boton boton-chico boton-borde" onClick={() => setEditando(g)}>
                            Editar
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
        </div>
      ))}
      {editando && datos && (
        <Dialogo titulo={`Plan de ${editando.nombre}`} alCerrar={() => setEditando(null)}>
          <EditarGrado
            grado={editando}
            catalogo={datos.catalogo[editando.nivel] ?? []}
            anio={anio}
            alGuardar={(grados) => {
              setDatos({ ...datos, grados });
              setEditando(null);
            }}
          />
        </Dialogo>
      )}
    </>
  );
}

function FormSeccion({ s, anio, opciones, alTerminar }: { s: Seccion | null; anio: number; opciones: Opciones; alTerminar: () => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [ambientes, setAmbientes] = useState(opciones.ambientes);
  const [nuevaAula, setNuevaAula] = useState('');
  const [f, setF] = useState({
    grado_id: s?.grado_id ?? opciones.grados[0]?.id ?? 0,
    nombre: s?.nombre ?? '',
    turno: s?.turno ?? 'manana',
    ambiente_id: s?.ambiente_id ?? '',
    tutor_personal_id: s?.tutor_personal_id ?? '',
    vacantes: s?.vacantes ? String(s.vacantes) : '',
  });

  async function guardar(e: FormEvent) {
    e.preventDefault();
    const ok = await accion.ejecutar(() =>
      pedir(s ? `/secciones/${s.id}` : '/secciones', {
        metodo: s ? 'PUT' : 'POST',
        cuerpo: {
          anio_escolar_id: anio,
          grado_id: Number(f.grado_id),
          nombre: f.nombre,
          turno: f.turno,
          ambiente_id: f.ambiente_id ? Number(f.ambiente_id) : null,
          tutor_personal_id: f.tutor_personal_id ? Number(f.tutor_personal_id) : null,
          vacantes: f.vacantes ? Number(f.vacantes) : null,
        },
      }),
    );
    if (ok) alTerminar();
  }

  return (
    <form onSubmit={guardar} noValidate>
      <div className="campos">
        <Campo etiqueta="Grado">
          <select value={f.grado_id} onChange={(e) => setF({ ...f, grado_id: Number(e.target.value) })}>
            {opciones.grados.map((g) => (
              <option key={g.id} value={g.id}>
                {g.nombre}
              </option>
            ))}
          </select>
        </Campo>
        <Campo etiqueta="Sección" ayuda="A, B, Única…">
          <input value={f.nombre} maxLength={10} onChange={(e) => setF({ ...f, nombre: e.target.value })} />
        </Campo>
        <Campo etiqueta="Turno">
          <select value={f.turno} onChange={(e) => setF({ ...f, turno: e.target.value })}>
            {Object.entries(TURNO).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </Campo>
        <Campo etiqueta="Vacantes">
          <input inputMode="numeric" maxLength={2} value={f.vacantes} onChange={(e) => setF({ ...f, vacantes: e.target.value.replace(/\D/g, '') })} />
        </Campo>
        <Campo etiqueta="Aula">
          <select value={f.ambiente_id} onChange={(e) => setF({ ...f, ambiente_id: e.target.value })}>
            <option value="">Sin asignar</option>
            {ambientes.map((a) => (
              <option key={a.id} value={a.id}>
                {a.nombre}
              </option>
            ))}
          </select>
        </Campo>
        <Campo etiqueta="Tutor(a)">
          <select value={f.tutor_personal_id} onChange={(e) => setF({ ...f, tutor_personal_id: e.target.value })}>
            <option value="">Sin asignar</option>
            {opciones.personal.map((p) => (
              <option key={p.id} value={p.id}>
                {p.nombre}
              </option>
            ))}
          </select>
        </Campo>
      </div>
      <div className="filtros" style={{ marginTop: 12 }}>
        <Campo etiqueta="¿El aula no está en la lista?">
          <input placeholder="Nombre del aula nueva" value={nuevaAula} onChange={(e) => setNuevaAula(e.target.value)} />
        </Campo>
        <button
          type="button"
          className="boton boton-claro"
          disabled={!nuevaAula.trim() || accion.enviando}
          onClick={() =>
            accion.ejecutar(async () => {
              const a = await pedir<{ id: number; nombre: string }>('/ambientes', { metodo: 'POST', cuerpo: { nombre: nuevaAula } });
              setAmbientes([...ambientes, a]);
              setF({ ...f, ambiente_id: a.id });
              setNuevaAula('');
            })
          }
        >
          Agregar aula
        </button>
      </div>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="submit" className="boton" disabled={accion.enviando}>
          {s ? 'Guardar' : 'Crear sección'}
        </button>
      </div>
    </form>
  );
}

function SeccionesDelAnio({ anio, anios, editable }: { anio: number; anios: AnioResumen[]; editable: boolean }) {
  const { pedir } = useSesion();
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Seccion[] } & Opciones>(`/secciones?anio_escolar_id=${anio}`), [anio]);
  const accion = useAccion();
  const [editando, setEditando] = useState<Seccion | 'nueva' | null>(null);

  return (
    <>
      {editable && (
        <div className="tarjeta" style={{ marginBottom: 18, display: 'flex', flexWrap: 'wrap', gap: 16, alignItems: 'flex-end', justifyContent: 'space-between' }}>
          <Copiar anios={anios} anio={anio} ruta="/secciones/copiar" que="las secciones" alTerminar={recargar} />
          <button type="button" className="boton" onClick={() => setEditando('nueva')}>
            + Nueva sección
          </button>
        </div>
      )}
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <div className="tarjeta">
        <div className="tabla-caja">
          <table className="tabla">
            <thead>
              <tr>
                <th>Grado y sección</th>
                <th>Turno</th>
                <th>Aula</th>
                <th>Tutor(a)</th>
                <th>Matriculados</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {datos?.data.map((s) => (
                <tr key={s.id}>
                  <td>
                    <b>
                      {s.grado} {s.nombre}
                    </b>
                  </td>
                  <td>{TURNO[s.turno]}</td>
                  <td>{s.ambiente ?? '—'}</td>
                  <td>{s.tutor ?? '—'}</td>
                  <td>
                    {s.matriculados}
                    {s.vacantes ? ` / ${s.vacantes}` : ''}
                  </td>
                  <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                    {editable && (
                      <>
                        <button type="button" className="boton boton-chico boton-borde" onClick={() => setEditando(s)}>
                          Editar
                        </button>{' '}
                        {s.matriculados === 0 && (
                          <button
                            type="button"
                            className="boton boton-chico boton-peligro"
                            onClick={() => accion.ejecutar(async () => { await pedir(`/secciones/${s.id}`, { metodo: 'DELETE' }); recargar(); })}
                          >
                            Borrar
                          </button>
                        )}
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos?.data.length === 0 && <p className="vacio">Aún no hay secciones en este año.</p>}
        </div>
      </div>
      {editando && datos && (
        <Dialogo titulo={editando === 'nueva' ? 'Nueva sección' : `Editar ${editando.grado} ${editando.nombre}`} alCerrar={() => setEditando(null)}>
          <FormSeccion
            s={editando === 'nueva' ? null : editando}
            anio={anio}
            opciones={datos}
            alTerminar={() => {
              setEditando(null);
              recargar();
            }}
          />
        </Dialogo>
      )}
    </>
  );
}

/** EDUN-45 / EDUN-46 · ADM-03 Plan de estudios y secciones del año. */
export function PlanEstudios() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const editable = puede(colegio, 'adm.plan_estudios.editar');
  const { anios, anio, setAnio, error } = useAnioEscolar();
  const [pestana, setPestana] = useState<'plan' | 'secciones'>('plan');

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Académico`} titulo="Plan de estudios y secciones" descripcion="Áreas del CNEB por grado, escala de calificación y secciones de cada año.">
        {anios.length > 0 && <SelectorAnio anios={anios} anio={anio} alCambiar={setAnio} />}
      </Titulo>
      <Pestanas
        opciones={[
          ['plan', 'Plan de estudios'],
          ['secciones', 'Secciones'],
        ]}
        actual={pestana}
        alCambiar={setPestana}
      />
      <Aviso tipo="error">{error}</Aviso>
      {anio === null && !error && <p className="vacio">Primero crea el año escolar en Configuración del colegio.</p>}
      {anio !== null && pestana === 'plan' && <PlanPorGrado key={anio} anio={anio} anios={anios} editable={editable} />}
      {anio !== null && pestana === 'secciones' && <SeccionesDelAnio key={anio} anio={anio} anios={anios} editable={editable} />}
    </>
  );
}
