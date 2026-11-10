import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Pestanas, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from './anios';
import { SelectorAnio } from './SelectorAnio';

type Version = { id: number; version: number; contenido: string; publicado_at: string; aceptaciones: number };
type Texto = { version: string; contenido: string; publicado_at: string } | null;
type EstadoCons = { estado: string; version: string | null; desactualizado: boolean };
type FilaCons = { id: number; alumno: string; seccion: string | null; consentimientos: Record<string, EstadoCons> };
type Pendiente = { id: number; tipo: string; detalle: string; created_at: string; alumno: string; codigo_estudiante_siagie: string | null };

const ESTADO: Record<string, [string, string]> = {
  otorgado: ['Sí', 'insignia-ok'],
  negado: ['No', 'insignia-rojo'],
  revocado: ['Revocado', 'insignia-rojo'],
  pendiente: ['Pendiente', 'insignia-ambar'],
};

/** EDUN-54 · ADM-H12 Condiciones económicas del año, versionadas. */
export function CondicionesEconomicas() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { anios, anio, setAnio } = useAnioEscolar();
  const { datos, setDatos, error } = useCarga(() => (anio ? pedir<{ data: Version[] }>(`/condiciones-economicas?anio_escolar_id=${anio}`) : Promise.resolve({ data: [] })), [anio]);
  const accion = useAccion();
  const [texto, setTexto] = useState<string | null>(null);
  const vigente = datos?.data[0];

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Matrícula`} titulo="Condiciones económicas" descripcion="Texto que el apoderado acepta antes de matricular (Ley 26549). Cada cambio crea una versión nueva.">
        {anios.length > 0 && <SelectorAnio anios={anios} anio={anio} alCambiar={setAnio} />}
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tarjeta" style={{ maxWidth: 900 }}>
        <h2>{vigente ? `Versión vigente ${vigente.version}` : 'Sin publicar'}</h2>
        {vigente && <p className="ayuda">Publicada el {fechaHora(vigente.publicado_at)} · aceptada en {vigente.aceptaciones} matrículas</p>}
        {puede(colegio, 'adm.matricula.editar') ? (
          <>
            <textarea
              className="control"
              style={{ width: '100%', height: 280, padding: 12, marginTop: 10 }}
              aria-label="Texto de las condiciones económicas"
              value={texto ?? vigente?.contenido ?? ''}
              onChange={(e) => setTexto(e.target.value)}
              placeholder="Monto de matrícula y pensiones, número de cuotas, fechas de vencimiento, mora, posibles aumentos…"
            />
            <div className="acciones">
              <button
                type="button"
                className="boton"
                disabled={accion.enviando || !texto || texto === vigente?.contenido}
                onClick={() =>
                  accion.ejecutar(async () => {
                    setDatos(await pedir<{ data: Version[] }>('/condiciones-economicas', { metodo: 'POST', cuerpo: { anio_escolar_id: anio, contenido: texto } }));
                    setTexto(null);
                  }, 'Nueva versión publicada.')
                }
              >
                Publicar nueva versión
              </button>
            </div>
          </>
        ) : (
          <p style={{ whiteSpace: 'pre-wrap' }}>{vigente?.contenido}</p>
        )}
        {datos && datos.data.length > 1 && (
          <details style={{ marginTop: 12 }}>
            <summary className="ayuda">Versiones anteriores</summary>
            {datos.data.slice(1).map((v) => (
              <p key={v.id} className="ayuda">
                Versión {v.version} · {fechaHora(v.publicado_at)} · {v.aceptaciones} aceptaciones
              </p>
            ))}
          </details>
        )}
      </div>
    </>
  );
}

/** EDUN-41 · SEG-04 Consentimientos de datos e imagen (Ley 29733). */
export function ConsentimientosColegio() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [pestana, setPestana] = useState<'estado' | 'textos'>('estado');
  const [soloPendientes, setSoloPendientes] = useState(false);
  const { datos, setDatos, error } = useCarga(() => pedir<{ textos: Record<string, Texto>; data: FilaCons[] }>('/consentimientos'));
  const accion = useAccion();
  const [nuevo, setNuevo] = useState({ finalidad: 'datos', version: '', contenido: '' });
  const filas = datos?.data.filter((f) => !soloPendientes || Object.values(f.consentimientos).some((c) => c.estado === 'pendiente' || c.desactualizado)) ?? [];

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Matrícula`} titulo="Consentimientos" descripcion="Autorizaciones de las familias para el tratamiento de datos y el uso de la imagen de los alumnos." />
      <Pestanas opciones={[['estado', 'Por alumno'], ['textos', 'Textos']]} actual={pestana} alCambiar={setPestana} />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {pestana === 'estado' && (
        <div className="tarjeta">
          <label className="casilla" style={{ marginBottom: 10 }}>
            <input type="checkbox" checked={soloPendientes} onChange={(e) => setSoloPendientes(e.target.checked)} /> Solo pendientes o con texto antiguo
          </label>
          <div className="tabla-caja">
            <table className="tabla">
              <thead>
                <tr>
                  <th>Alumno</th>
                  <th>Sección</th>
                  <th>Datos</th>
                  <th>Imagen</th>
                </tr>
              </thead>
              <tbody>
                {filas.map((f) => (
                  <tr key={f.id}>
                    <td>{f.alumno}</td>
                    <td>{f.seccion ?? '—'}</td>
                    {['datos', 'imagen'].map((k) => {
                      const c = f.consentimientos[k];
                      const [t, clase] = ESTADO[c.estado] ?? [c.estado, ''];
                      return (
                        <td key={k}>
                          <span className={`insignia ${clase}`}>{t}</span>
                          {c.desactualizado && <small className="ayuda"> (v. {c.version})</small>}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
            {filas.length === 0 && <p className="vacio">No hay alumnos en esta lista.</p>}
          </div>
        </div>
      )}
      {pestana === 'textos' && datos && (
        <div className="rejilla">
          {Object.entries({ datos: 'Datos personales', imagen: 'Imagen' }).map(([k, nombre]) => (
            <div key={k} className="tarjeta">
              <h2>{nombre}</h2>
              {datos.textos[k] ? (
                <>
                  <p className="ayuda">Versión {datos.textos[k]!.version} · {fecha(datos.textos[k]!.publicado_at)}</p>
                  <p style={{ whiteSpace: 'pre-wrap', fontSize: 14 }}>{datos.textos[k]!.contenido}</p>
                </>
              ) : (
                <Aviso tipo="ambar">Sin texto publicado: las familias aún no pueden aceptar.</Aviso>
              )}
            </div>
          ))}
          {puede(colegio, 'seg.consentimientos.registrar') && (
            <div className="tarjeta" style={{ gridColumn: '1 / -1' }}>
              <h2>Publicar una versión</h2>
              <div className="campos" style={{ marginTop: 10 }}>
                <Campo etiqueta="Finalidad">
                  <select value={nuevo.finalidad} onChange={(e) => setNuevo({ ...nuevo, finalidad: e.target.value })}>
                    <option value="datos">Datos personales</option>
                    <option value="imagen">Imagen</option>
                  </select>
                </Campo>
                <Campo etiqueta="Versión" ayuda="Por ejemplo 2027.1">
                  <input value={nuevo.version} onChange={(e) => setNuevo({ ...nuevo, version: e.target.value })} />
                </Campo>
              </div>
              <textarea className="control" aria-label="Texto del consentimiento" style={{ width: '100%', height: 180, padding: 12, marginTop: 10 }}
                value={nuevo.contenido} onChange={(e) => setNuevo({ ...nuevo, contenido: e.target.value })} />
              <div className="acciones">
                <button type="button" className="boton" disabled={accion.enviando || !nuevo.version || !nuevo.contenido}
                  onClick={() => accion.ejecutar(async () => {
                    const r = await pedir<{ textos: Record<string, Texto> }>('/consentimientos/textos', { metodo: 'POST', cuerpo: nuevo });
                    setDatos({ ...datos, textos: r.textos });
                    setNuevo({ ...nuevo, version: '', contenido: '' });
                  }, 'Texto publicado. Las familias verán la versión nueva.')}>
                  Publicar
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </>
  );
}

/** EDUN-57 / EDUN-58 · Movimientos por registrar también en el SIAGIE. */
export function PendientesSiagie() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Pendiente[] }>('/siagie/pendientes'));
  const accion = useAccion();
  return (
    <>
      <Titulo migas={`${colegio.nombre} · SIAGIE`} titulo="Pendientes para el SIAGIE" descripcion="Retiros, traslados y cambios de sección que también deben registrarse en el SIAGIE." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <div className="tarjeta">
        <div className="tabla-caja">
          <table className="tabla">
            <thead>
              <tr>
                <th>Alumno</th>
                <th>Movimiento</th>
                <th>Registrado</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {datos?.data.map((p) => (
                <tr key={p.id}>
                  <td>
                    {p.alumno}
                    <br />
                    <small className="ayuda">{p.codigo_estudiante_siagie ?? 'sin código SIAGIE'}</small>
                  </td>
                  <td>{p.detalle}</td>
                  <td>{fechaHora(p.created_at)}</td>
                  <td style={{ textAlign: 'right' }}>
                    <button type="button" className="boton boton-chico boton-claro" disabled={accion.enviando}
                      onClick={() => accion.ejecutar(async () => { await pedir(`/siagie/pendientes/${p.id}/atendido`, { metodo: 'POST' }); recargar(); })}>
                      Ya está en el SIAGIE
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos?.data.length === 0 && <p className="vacio">No hay pendientes.</p>}
        </div>
      </div>
    </>
  );
}
