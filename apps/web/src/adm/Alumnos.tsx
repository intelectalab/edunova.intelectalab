import { useState, type FormEvent } from 'react';
import { apiArchivo, guardarArchivo } from '../api/cliente';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { mensajeError, useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from './anios';
import { SelectorAnio } from './SelectorAnio';

type Fila = {
  id: number;
  apellido_paterno: string;
  apellido_materno: string | null;
  nombres: string;
  numero_documento: string | null;
  codigo_estudiante_siagie: string | null;
  seccion: string | null;
  matricula: string | null;
};
type Apoderado = {
  numero_documento: string;
  apellidos: string;
  nombres: string;
  parentesco: string;
  celular: string;
  email: string;
  es_responsable_pago: boolean;
  puede_recoger: boolean;
  recibe_notificaciones: boolean;
};
type Autorizado = { nombres: string; numero_documento: string; parentesco: string; vigente_hasta: string };
type Ficha = {
  id: number;
  numero_documento: string | null;
  codigo_estudiante_siagie: string | null;
  apellido_paterno: string;
  apellido_materno: string | null;
  nombres: string;
  sexo: string | null;
  fecha_nacimiento: string | null;
  apoderados: (Apoderado & { celular: string | null; email: string | null })[];
  autorizados: (Autorizado & { vigente_hasta: string | null })[];
  matriculas: { anio: number; anio_escolar_id: number; seccion_id: number; seccion: string; estado: string }[];
};
type Seccion = { id: number; grado: string; nombre: string };
type FilaImportada = { fila: number; datos: Record<string, string | null> & { apoderado: { nombres: string; apellidos: string } | null }; errores: string[] };
type Vista = { token: string; total: number; validas: number; con_errores: number; filas: FilaImportada[] };

const PARENTESCOS: Record<string, string> = {
  padre: 'Padre',
  madre: 'Madre',
  abuelo: 'Abuelo',
  abuela: 'Abuela',
  tio: 'Tío',
  tia: 'Tía',
  hermano: 'Hermano',
  hermana: 'Hermana',
  tutor_legal: 'Tutor(a) legal',
  otro: 'Otro',
};
const NUEVO_APODERADO: Apoderado = { numero_documento: '', apellidos: '', nombres: '', parentesco: 'madre', celular: '', email: '', es_responsable_pago: false, puede_recoger: true, recibe_notificaciones: true };

function FormAlumno({ ficha, anio, secciones, alTerminar }: { ficha: Ficha | null; anio: number; secciones: Seccion[]; alTerminar: () => void }) {
  const { pedir, estado } = useSesion();
  const puedeMatricular = puede(estado!.colegio_activo, 'adm.matricula.crear');
  const puedeGuardar = puede(estado!.colegio_activo, ficha ? 'adm.alumnos.editar' : 'adm.alumnos.crear');
  const accion = useAccion();
  const matriculaAnio = ficha?.matriculas.find((m) => m.anio_escolar_id === anio);
  const [a, setA] = useState({
    numero_documento: ficha?.numero_documento ?? '',
    codigo_estudiante_siagie: ficha?.codigo_estudiante_siagie ?? '',
    apellido_paterno: ficha?.apellido_paterno ?? '',
    apellido_materno: ficha?.apellido_materno ?? '',
    nombres: ficha?.nombres ?? '',
    sexo: ficha?.sexo ?? '',
    fecha_nacimiento: ficha?.fecha_nacimiento ?? '',
    seccion_id: matriculaAnio ? String(matriculaAnio.seccion_id) : '',
  });
  const [apoderados, setApoderados] = useState<Apoderado[]>(
    ficha?.apoderados.map((p) => ({ ...p, celular: p.celular ?? '', email: p.email ?? '' })) ?? [{ ...NUEVO_APODERADO, es_responsable_pago: true }],
  );
  const [autorizados, setAutorizados] = useState<Autorizado[]>(ficha?.autorizados.map((p) => ({ ...p, vigente_hasta: p.vigente_hasta ?? '' })) ?? []);
  const campo = (k: keyof typeof a) => (e: { target: { value: string } }) => setA({ ...a, [k]: e.target.value });
  const apo = (i: number, k: keyof Apoderado, v: string | boolean) => setApoderados(apoderados.map((p, j) => (j === i ? { ...p, [k]: v } : p)));
  const aut = (i: number, k: keyof Autorizado, v: string) => setAutorizados(autorizados.map((p, j) => (j === i ? { ...p, [k]: v } : p)));
  const vacio = (v: string) => (v.trim() === '' ? null : v.trim());

  async function guardar(e: FormEvent) {
    e.preventDefault();
    const cuerpo = {
      ...Object.fromEntries(Object.entries(a).map(([k, v]) => [k, vacio(v)])),
      seccion_id: a.seccion_id && !matriculaAnio ? Number(a.seccion_id) : null,
      apoderados: apoderados.map((p) => ({ ...p, celular: vacio(p.celular), email: vacio(p.email) })),
      autorizados: autorizados.map((p) => ({ ...p, vigente_hasta: vacio(p.vigente_hasta) })),
    };
    if (await accion.ejecutar(() => pedir(ficha ? `/alumnos/${ficha.id}` : '/alumnos', { metodo: ficha ? 'PUT' : 'POST', cuerpo }))) alTerminar();
  }

  return (
    <form onSubmit={guardar} noValidate>
      <h3 style={{ margin: '8px 0' }}>Datos del alumno</h3>
      <div className="campos">
        <Campo etiqueta="DNI">
          <input inputMode="numeric" maxLength={8} value={a.numero_documento} onChange={campo('numero_documento')} />
        </Campo>
        <Campo etiqueta="Código SIAGIE" ayuda="14 dígitos">
          <input inputMode="numeric" maxLength={14} value={a.codigo_estudiante_siagie} onChange={campo('codigo_estudiante_siagie')} />
        </Campo>
        <Campo etiqueta="Apellido paterno">
          <input value={a.apellido_paterno} onChange={campo('apellido_paterno')} />
        </Campo>
        <Campo etiqueta="Apellido materno">
          <input value={a.apellido_materno} onChange={campo('apellido_materno')} />
        </Campo>
        <Campo etiqueta="Nombres">
          <input value={a.nombres} onChange={campo('nombres')} />
        </Campo>
        <Campo etiqueta="Sexo">
          <select value={a.sexo} onChange={campo('sexo')}>
            <option value="">—</option>
            <option value="M">Mujer</option>
            <option value="H">Hombre</option>
          </select>
        </Campo>
        <Campo etiqueta="Fecha de nacimiento">
          <input type="date" value={a.fecha_nacimiento} onChange={campo('fecha_nacimiento')} />
        </Campo>
        <Campo etiqueta="Sección del año" ayuda={matriculaAnio ? 'Ya está matriculado este año.' : undefined}>
          <select value={a.seccion_id} disabled={!!matriculaAnio || !puedeMatricular} onChange={campo('seccion_id')}>
            <option value="">Sin matricular</option>
            {secciones.map((s) => (
              <option key={s.id} value={s.id}>
                {s.grado} {s.nombre}
              </option>
            ))}
          </select>
        </Campo>
      </div>

      <h3 style={{ margin: '18px 0 8px' }}>Apoderados</h3>
      {apoderados.map((p, i) => (
        <fieldset key={i} style={{ border: '1px solid var(--en-linea)', borderRadius: 8, padding: 12, marginBottom: 10 }}>
          <div className="campos">
            <Campo etiqueta="DNI">
              <input inputMode="numeric" maxLength={8} value={p.numero_documento} onChange={(e) => apo(i, 'numero_documento', e.target.value)} />
            </Campo>
            <Campo etiqueta="Apellidos">
              <input value={p.apellidos} onChange={(e) => apo(i, 'apellidos', e.target.value)} />
            </Campo>
            <Campo etiqueta="Nombres">
              <input value={p.nombres} onChange={(e) => apo(i, 'nombres', e.target.value)} />
            </Campo>
            <Campo etiqueta="Parentesco">
              <select value={p.parentesco} onChange={(e) => apo(i, 'parentesco', e.target.value)}>
                {Object.entries(PARENTESCOS).map(([k, v]) => (
                  <option key={k} value={k}>
                    {v}
                  </option>
                ))}
              </select>
            </Campo>
            <Campo etiqueta="Celular">
              <input inputMode="tel" value={p.celular} onChange={(e) => apo(i, 'celular', e.target.value)} />
            </Campo>
            <Campo etiqueta="Correo">
              <input type="email" value={p.email} onChange={(e) => apo(i, 'email', e.target.value)} />
            </Campo>
          </div>
          <div className="casillas" style={{ marginTop: 8 }}>
            <label className="casilla">
              <input type="checkbox" checked={p.es_responsable_pago} onChange={(e) => apo(i, 'es_responsable_pago', e.target.checked)} /> Responsable de pago
            </label>
            <label className="casilla">
              <input type="checkbox" checked={p.puede_recoger} onChange={(e) => apo(i, 'puede_recoger', e.target.checked)} /> Puede recoger al alumno
            </label>
            <label className="casilla">
              <input type="checkbox" checked={p.recibe_notificaciones} onChange={(e) => apo(i, 'recibe_notificaciones', e.target.checked)} /> Recibe avisos
            </label>
            <button type="button" className="boton boton-chico boton-borde" onClick={() => setApoderados(apoderados.filter((_, j) => j !== i))}>
              Quitar
            </button>
          </div>
        </fieldset>
      ))}
      <button type="button" className="boton boton-chico boton-claro" onClick={() => setApoderados([...apoderados, { ...NUEVO_APODERADO, parentesco: 'padre' }])}>
        + Apoderado
      </button>

      <h3 style={{ margin: '18px 0 4px' }}>Otros autorizados a recoger</h3>
      <p className="ayuda">Personas que no son apoderados pero pueden recoger al alumno (portería las verifica).</p>
      {autorizados.map((p, i) => (
        <div key={i} className="filtros" style={{ marginBottom: 6 }}>
          <Campo etiqueta="Nombre completo">
            <input value={p.nombres} onChange={(e) => aut(i, 'nombres', e.target.value)} />
          </Campo>
          <Campo etiqueta="DNI">
            <input inputMode="numeric" maxLength={12} value={p.numero_documento} onChange={(e) => aut(i, 'numero_documento', e.target.value)} />
          </Campo>
          <Campo etiqueta="Relación">
            <input value={p.parentesco} onChange={(e) => aut(i, 'parentesco', e.target.value)} />
          </Campo>
          <Campo etiqueta="Hasta">
            <input type="date" value={p.vigente_hasta} onChange={(e) => aut(i, 'vigente_hasta', e.target.value)} />
          </Campo>
          <button type="button" className="boton boton-chico boton-borde" onClick={() => setAutorizados(autorizados.filter((_, j) => j !== i))}>
            Quitar
          </button>
        </div>
      ))}
      <button type="button" className="boton boton-chico boton-claro" onClick={() => setAutorizados([...autorizados, { nombres: '', numero_documento: '', parentesco: '', vigente_hasta: '' }])}>
        + Autorizado
      </button>

      <Aviso tipo="error">{accion.error}</Aviso>
      {puedeGuardar && (
        <div className="acciones">
          <button type="submit" className="boton" disabled={accion.enviando}>
            {accion.enviando ? 'Guardando…' : ficha ? 'Guardar cambios' : 'Registrar alumno'}
          </button>
        </div>
      )}
    </form>
  );
}

function Ficha({ id, anio, secciones, alTerminar }: { id: number; anio: number; secciones: Seccion[]; alTerminar: () => void }) {
  const { pedir } = useSesion();
  const { datos, error } = useCarga(() => pedir<Ficha>(`/alumnos/${id}/ficha`), [id]);
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;
  return <FormAlumno ficha={datos} anio={anio} secciones={secciones} alTerminar={alTerminar} />;
}

function Importar({ anio, alTerminar }: { anio: number; alTerminar: () => void }) {
  const { pedir, token } = useSesion();
  const accion = useAccion();
  const [vista, setVista] = useState<Vista | null>(null);
  const [resultado, setResultado] = useState<Record<string, number> | null>(null);
  const [soloErrores, setSoloErrores] = useState(false);

  async function subir(archivo: File | undefined) {
    if (!archivo) return;
    const cuerpo = new FormData();
    cuerpo.append('anio_escolar_id', String(anio));
    cuerpo.append('archivo', archivo);
    await accion.ejecutar(async () => setVista(await pedir<Vista>('/alumnos/importar/vista-previa', { metodo: 'POST', cuerpo })));
  }

  if (resultado) {
    return (
      <>
        <Aviso tipo="ok">
          Listo: {resultado.alumnos_nuevos} alumnos nuevos, {resultado.matriculas} matrículas y {resultado.apoderados} apoderados.
          {resultado.omitidas ? ` Se omitieron ${resultado.omitidas} filas con errores.` : ''}
        </Aviso>
        <div className="acciones">
          <button type="button" className="boton" onClick={alTerminar}>
            Cerrar
          </button>
        </div>
      </>
    );
  }

  return (
    <>
      <ol className="ayuda" style={{ paddingLeft: 18 }}>
        <li>Descarga la plantilla (trae los grados y secciones del año).</li>
        <li>Llénala con una fila por alumno y súbela. Revisarás los errores antes de guardar.</li>
      </ol>
      <div className="acciones" style={{ marginTop: 0 }}>
        <button
          type="button"
          className="boton boton-claro"
          onClick={async () => {
            try {
              guardarArchivo(await apiArchivo(`/alumnos/importar/plantilla?anio_escolar_id=${anio}`, token), 'plantilla-alumnos.xlsx');
            } catch (e) {
              accion.setError(mensajeError(e));
            }
          }}
        >
          Descargar plantilla
        </button>
        <label className="boton">
          {vista ? 'Subir otro archivo' : 'Subir archivo'}
          <input type="file" accept=".xlsx,.csv" hidden onChange={(e) => void subir(e.target.files?.[0])} />
        </label>
      </div>
      <Aviso tipo="error">{accion.error}</Aviso>
      {accion.enviando && <p className="ayuda">Revisando el archivo…</p>}
      {vista && (
        <>
          <Aviso tipo={vista.con_errores ? 'ambar' : 'ok'}>
            {vista.total} filas: {vista.validas} listas para importar{vista.con_errores ? ` y ${vista.con_errores} con errores (no se importarán)` : ''}.
          </Aviso>
          <label className="casilla" style={{ margin: '8px 0' }}>
            <input type="checkbox" checked={soloErrores} onChange={(e) => setSoloErrores(e.target.checked)} /> Ver solo las filas con errores
          </label>
          <div className="tabla-caja" style={{ maxHeight: 320, overflowY: 'auto' }}>
            <table className="tabla">
              <thead>
                <tr>
                  <th>Fila</th>
                  <th>Alumno</th>
                  <th>Sección</th>
                  <th>Resultado</th>
                </tr>
              </thead>
              <tbody>
                {vista.filas
                  .filter((f) => !soloErrores || f.errores.length > 0)
                  .map((f) => (
                    <tr key={f.fila}>
                      <td>{f.fila}</td>
                      <td>
                        {`${[f.datos.apellido_paterno, f.datos.apellido_materno].filter(Boolean).join(' ')}, ${f.datos.nombres ?? ''}`}
                        <br />
                        <small className="ayuda">{f.datos.dni ?? 'sin DNI'}</small>
                      </td>
                      <td>
                        {f.datos.grado} {f.datos.seccion}
                      </td>
                      <td>
                        {f.errores.length ? (
                          <ul style={{ margin: 0, paddingLeft: 16, color: 'var(--en-rojo)' }}>
                            {f.errores.map((e) => (
                              <li key={e}>{e}</li>
                            ))}
                          </ul>
                        ) : (
                          <span className="insignia insignia-ok">{f.datos.alumno_id ? 'Ya existe: se matricula' : 'Se crea'}</span>
                        )}
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
          <div className="acciones">
            <button
              type="button"
              className="boton"
              disabled={accion.enviando || vista.validas === 0}
              onClick={() => accion.ejecutar(async () => setResultado(await pedir('/alumnos/importar/confirmar', { metodo: 'POST', cuerpo: { token: vista.token } })))}
            >
              Importar {vista.validas} alumnos
            </button>
          </div>
        </>
      )}
    </>
  );
}

/** EDUN-50 / EDUN-51 · ADM-06 Alumnos: registro con apoderados e importación desde Excel. */
export function Alumnos() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { anios, anio, setAnio, error: errorAnios } = useAnioEscolar();
  const [buscar, setBuscar] = useState('');
  const [seccion, setSeccion] = useState('');
  const consulta = new URLSearchParams({ ...(anio ? { anio_escolar_id: String(anio) } : {}), ...(buscar ? { buscar } : {}), ...(seccion ? { seccion_id: seccion } : {}) }).toString();
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Fila[] }>(`/alumnos?${consulta}`), [consulta]);
  const secciones = useCarga(() => (anio ? pedir<{ data: Seccion[] }>(`/secciones?anio_escolar_id=${anio}`) : Promise.resolve({ data: [] })), [anio]);
  const [dialogo, setDialogo] = useState<{ tipo: 'nuevo' | 'ficha' | 'importar'; id?: number } | null>(null);
  const cerrar = () => {
    setDialogo(null);
    recargar();
  };
  const puedeImportar = puede(colegio, 'adm.alumnos.crear') && puede(colegio, 'adm.matricula.crear');

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Matrícula`} titulo="Alumnos" descripcion="Registro de alumnos con sus apoderados y autorizados a recoger, e importación desde Excel.">
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {puedeImportar && anio && (
            <button type="button" className="boton boton-claro" onClick={() => setDialogo({ tipo: 'importar' })}>
              Importar desde Excel
            </button>
          )}
          {puede(colegio, 'adm.alumnos.crear') && anio && (
            <button type="button" className="boton" onClick={() => setDialogo({ tipo: 'nuevo' })}>
              + Registrar alumno
            </button>
          )}
        </div>
      </Titulo>
      <div className="tarjeta">
        <div className="filtros">
          {anios.length > 0 && <SelectorAnio anios={anios} anio={anio} alCambiar={setAnio} />}
          <Campo etiqueta="Buscar">
            <input placeholder="Apellidos, nombres, DNI o SIAGIE" value={buscar} onChange={(e) => setBuscar(e.target.value)} />
          </Campo>
          <Campo etiqueta="Sección">
            <select value={seccion} onChange={(e) => setSeccion(e.target.value)}>
              <option value="">Todas</option>
              {secciones.datos?.data.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.grado} {s.nombre}
                </option>
              ))}
            </select>
          </Campo>
        </div>
        <Aviso tipo="error">{error ?? errorAnios}</Aviso>
        <div className="tabla-caja">
          <table className="tabla">
            <thead>
              <tr>
                <th>Alumno</th>
                <th>DNI</th>
                <th>Código SIAGIE</th>
                <th>Sección</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {datos?.data.map((a) => (
                <tr key={a.id}>
                  <td>
                    <b>{`${[a.apellido_paterno, a.apellido_materno].filter(Boolean).join(' ')}, ${a.nombres}`}</b>
                  </td>
                  <td>{a.numero_documento ?? '—'}</td>
                  <td>{a.codigo_estudiante_siagie ?? '—'}</td>
                  <td>{a.seccion ?? <span className="insignia insignia-ambar">Sin matrícula</span>}</td>
                  <td style={{ textAlign: 'right' }}>
                    <button type="button" className="boton boton-chico boton-borde" onClick={() => setDialogo({ tipo: 'ficha', id: a.id })}>
                      Ficha
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos?.data.length === 0 && <p className="vacio">No hay alumnos con esos filtros.</p>}
        </div>
      </div>
      {dialogo && anio && (
        <Dialogo titulo={dialogo.tipo === 'importar' ? 'Importar alumnos desde Excel' : dialogo.tipo === 'nuevo' ? 'Registrar alumno' : 'Ficha del alumno'} alCerrar={() => setDialogo(null)}>
          {dialogo.tipo === 'importar' && <Importar anio={anio} alTerminar={cerrar} />}
          {dialogo.tipo === 'nuevo' && <FormAlumno ficha={null} anio={anio} secciones={secciones.datos?.data ?? []} alTerminar={cerrar} />}
          {dialogo.tipo === 'ficha' && dialogo.id && <Ficha id={dialogo.id} anio={anio} secciones={secciones.datos?.data ?? []} alTerminar={cerrar} />}
        </Dialogo>
      )}
    </>
  );
}
