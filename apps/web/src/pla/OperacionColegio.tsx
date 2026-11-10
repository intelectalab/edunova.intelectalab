import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Paso = { clave: string; paso: string; quien: string; verificacion: string; historia: string; hecho: boolean; manual: boolean; nota: string | null };
type Exportacion = { id: number; estado: string; motivo: string; bytes: number | null; resumen: { archivos: number; modulos: Record<string, Record<string, number>> } | null; error: string | null; created_at: string };

/** EDUN-212 · Lista de implementación del colegio y activación. */
export function ListaImplementacion({ id, estado, alCambiar }: { id: number; estado: string; alCambiar: () => void }) {
  const { pedir, estado: sesion } = useSesion();
  const esSuperadmin = sesion!.roles_plataforma.includes('superadmin');
  const { datos, setDatos, error } = useCarga(() => pedir<{ data: Paso[] }>(`/plataforma/instituciones/${id}/checklist`), [id]);
  const accion = useAccion();
  const hechos = datos?.data.filter((p) => p.hecho).length ?? 0;

  return (
    <div className="tarjeta" style={{ gridColumn: '1 / -1' }}>
      <div className="titulo-fila">
        <h2>
          Lista de implementación {datos && <small className="ayuda">({hechos} de {datos.data.length})</small>}
        </h2>
        {esSuperadmin && estado === 'implementacion' && (
          <button type="button" className="boton" disabled={accion.enviando}
            onClick={() => accion.ejecutar(async () => { await pedir(`/plataforma/instituciones/${id}/estado`, { metodo: 'PUT', cuerpo: { estado: 'activo' } }); alCambiar(); }, 'Colegio activado.')}>
            Activar colegio
          </button>
        )}
      </div>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tabla-caja" style={{ marginTop: 8 }}>
        <table className="tabla">
          <thead>
            <tr>
              <th />
              <th>Paso</th>
              <th>Quién</th>
              <th>Cómo se verifica</th>
            </tr>
          </thead>
          <tbody>
            {datos?.data.map((p) => (
              <tr key={p.clave}>
                <td style={{ width: 40 }}>
                  {p.manual && esSuperadmin ? (
                    <input type="checkbox" aria-label={p.paso} checked={p.hecho} disabled={accion.enviando}
                      onChange={(e) => accion.ejecutar(async () => setDatos(await pedir<{ data: Paso[] }>(`/plataforma/instituciones/${id}/checklist/${p.clave}`, { metodo: 'PUT', cuerpo: { hecho: e.target.checked } })))} />
                  ) : (
                    <span className={`insignia ${p.hecho ? 'insignia-ok' : 'insignia-ambar'}`}>{p.hecho ? '✓' : '…'}</span>
                  )}
                </td>
                <td>
                  <b>{p.paso}</b> <small className="ayuda">{p.historia}</small>
                  {p.nota && (
                    <>
                      <br />
                      <small className="ayuda">{p.nota}</small>
                    </>
                  )}
                </td>
                <td>{p.quien}</td>
                <td>
                  <small>{p.verificacion}</small>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/** EDUN-28 · Suspensión por falta de pago y reactivación. */
export function Suspension({ id, estado, programada, alCambiar }: { id: number; estado: string; programada: string | null; alCambiar: () => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState({ fecha: '', motivo: '' });
  const suspendido = estado === 'suspendido';

  return (
    <div className="tarjeta">
      <h2>Suspensión por falta de pago</h2>
      <Aviso tipo="error">{accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {suspendido ? (
        <>
          <Aviso tipo="ambar">El colegio está suspendido: nadie puede ingresar. Los datos se conservan y se pueden exportar.</Aviso>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando}
              onClick={() => accion.ejecutar(async () => { await pedir(`/plataforma/instituciones/${id}/estado`, { metodo: 'PUT', cuerpo: { estado: 'activo' } }); alCambiar(); }, 'Colegio reactivado.')}>
              Reactivar
            </button>
          </div>
        </>
      ) : programada ? (
        <>
          <p>
            Programada para el <b>{programada.slice(8, 10)}/{programada.slice(5, 7)}/{programada.slice(0, 4)}</b>. El promotor recibe avisos 7 y 3 días antes.
          </p>
          <div className="acciones">
            <button type="button" className="boton boton-borde" disabled={accion.enviando}
              onClick={() => accion.ejecutar(async () => { await pedir(`/plataforma/instituciones/${id}/suspension`, { metodo: 'DELETE' }); alCambiar(); }, 'Suspensión cancelada.')}>
              Cancelar suspensión
            </button>
          </div>
        </>
      ) : (
        <>
          <p className="ayuda">El promotor recibe avisos 7 y 3 días antes. Ese día nadie podrá ingresar; los datos se conservan.</p>
          <div className="campos">
            <Campo etiqueta="Fecha de suspensión">
              <input type="date" value={f.fecha} onChange={(e) => setF({ ...f, fecha: e.target.value })} />
            </Campo>
            <Campo etiqueta="Motivo" ancho>
              <input value={f.motivo} placeholder="Facturas de agosto y setiembre impagas" onChange={(e) => setF({ ...f, motivo: e.target.value })} />
            </Campo>
          </div>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando || !f.fecha || !f.motivo}
              onClick={() => accion.ejecutar(async () => { await pedir(`/plataforma/instituciones/${id}/suspension`, { metodo: 'POST', cuerpo: f }); alCambiar(); }, 'Suspensión programada.')}>
              Programar
            </button>
            <button type="button" className="boton boton-peligro" disabled={accion.enviando || !f.motivo}
              onClick={() => accion.ejecutar(async () => { await pedir(`/plataforma/instituciones/${id}/estado`, { metodo: 'PUT', cuerpo: { estado: 'suspendido', motivo: f.motivo } }); alCambiar(); }, 'Colegio suspendido.')}>
              Suspender ahora
            </button>
          </div>
        </>
      )}
    </div>
  );
}

/** EDUN-34 · Exportar todos los datos del colegio. */
export function Exportaciones({ id }: { id: number }) {
  const { pedir } = useSesion();
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Exportacion[] }>(`/plataforma/instituciones/${id}/exportaciones`), [id]);
  const accion = useAccion();
  const [motivo, setMotivo] = useState('portabilidad');

  return (
    <div className="tarjeta">
      <h2>Exportar datos</h2>
      <p className="ayuda">ZIP con un JSON y un Excel por módulo, y los archivos adjuntos. Para baja o portabilidad.</p>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="filtros">
        <Campo etiqueta="Motivo">
          <select value={motivo} onChange={(e) => setMotivo(e.target.value)}>
            <option value="portabilidad">Portabilidad</option>
            <option value="baja">Baja</option>
            <option value="respaldo">Respaldo</option>
          </select>
        </Campo>
        <button type="button" className="boton boton-claro" disabled={accion.enviando}
          onClick={() => accion.ejecutar(async () => { await pedir(`/plataforma/instituciones/${id}/exportaciones`, { metodo: 'POST', cuerpo: { motivo } }); recargar(); }, 'Preparando la exportación…')}>
          Exportar
        </button>
        <button type="button" className="boton boton-borde" onClick={recargar}>
          Actualizar
        </button>
      </div>
      {datos?.data.map((e) => (
        <div key={e.id} className="interruptor">
          <span>
            {fechaHora(e.created_at)} · {e.motivo}{' '}
            <span className={`insignia ${e.estado === 'lista' ? 'insignia-ok' : e.estado === 'fallida' ? 'insignia-rojo' : 'insignia-ambar'}`}>{e.estado}</span>
            {e.bytes ? <small className="ayuda"> · {(e.bytes / 1024 / 1024).toFixed(1)} MB · {e.resumen?.archivos ?? 0} archivos</small> : null}
          </span>
          {e.estado === 'lista' && (
            <button type="button" className="boton boton-chico boton-claro"
              onClick={() => accion.ejecutar(async () => { const r = await pedir<{ url: string }>(`/plataforma/instituciones/${id}/exportaciones/${e.id}/enlace`); window.open(r.url, '_blank', 'noopener'); })}>
              Descargar
            </button>
          )}
        </div>
      ))}
    </div>
  );
}
