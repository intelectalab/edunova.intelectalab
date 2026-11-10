import { Fragment, useState } from 'react';
import { apiArchivo, guardarArchivo } from '../api/cliente';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fechaHora, mensajeError, useCarga } from '../panel/utilidades';

type Registro = {
  id: number;
  fecha: string;
  usuario: string;
  accion: string;
  accion_nombre: string;
  tabla: string;
  registro_id: number | null;
  antes: Record<string, unknown> | null;
  despues: Record<string, unknown> | null;
  ip: string | null;
};
type Pagina = { data: Registro[]; current_page: number; last_page: number; total: number };
type Opciones = { acciones: { codigo: string; nombre: string }[]; usuarios: { id: number; nombre: string }[] };

function texto(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

/** Antes y después, campo por campo. */
function Cambios({ r }: { r: Registro }) {
  const claves = [...new Set([...Object.keys(r.antes ?? {}), ...Object.keys(r.despues ?? {})])];
  if (claves.length === 0) return <span className="ayuda">Sin detalle.</span>;
  return (
    <div className="cambios">
      <b>Campo</b>
      <b>Antes</b>
      <b>Después</b>
      {claves.map((k) => (
        <Fragment key={k}>
          <span>{k}</span>
          <span className="antes">{texto(r.antes?.[k])}</span>
          <span className="despues">{texto(r.despues?.[k])}</span>
        </Fragment>
      ))}
    </div>
  );
}

/** EDUN-40 · SEG-03 Auditoría: antes y después de cada cambio, filtros y exportación. Sin opción de borrar. */
export function Auditoria() {
  const { pedir, token, estado } = useSesion();
  const [filtros, setFiltros] = useState({ usuario: '', accion: '', desde: '', hasta: '' });
  const [pagina, setPagina] = useState(1);
  const [abierto, setAbierto] = useState<number | null>(null);
  const [errorExportar, setErrorExportar] = useState<string | null>(null);
  const consulta = new URLSearchParams(Object.entries(filtros).filter(([, v]) => v)).toString();
  const opciones = useCarga(() => pedir<Opciones>('/auditoria/opciones'));
  const { datos, error, cargando } = useCarga(() => pedir<Pagina>(`/auditoria?page=${pagina}${consulta ? `&${consulta}` : ''}`), [consulta, pagina]);

  const filtro = (k: keyof typeof filtros) => (e: { target: { value: string } }) => {
    setFiltros({ ...filtros, [k]: e.target.value });
    setPagina(1);
  };

  async function exportar() {
    setErrorExportar(null);
    try {
      guardarArchivo(await apiArchivo(`/auditoria/exportar${consulta ? `?${consulta}` : ''}`, token), 'auditoria.csv');
    } catch (e) {
      setErrorExportar(mensajeError(e));
    }
  }

  return (
    <>
      <Titulo
        migas={`${estado!.colegio_activo!.nombre} · Institución`}
        titulo="Auditoría"
        descripcion="Registro de los cambios importantes: quién, cuándo y qué cambió. No se puede editar ni borrar."
      >
        <button type="button" className="boton boton-claro" onClick={exportar}>
          Exportar CSV
        </button>
      </Titulo>
      <div className="tarjeta">
        <div className="filtros">
          <Campo etiqueta="Usuario">
            <select value={filtros.usuario} onChange={filtro('usuario')}>
              <option value="">Todos</option>
              {opciones.datos?.usuarios.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.nombre}
                </option>
              ))}
            </select>
          </Campo>
          <Campo etiqueta="Acción">
            <select value={filtros.accion} onChange={filtro('accion')}>
              <option value="">Todas</option>
              {opciones.datos?.acciones.map((a) => (
                <option key={a.codigo} value={a.codigo}>
                  {a.nombre}
                </option>
              ))}
            </select>
          </Campo>
          <Campo etiqueta="Desde">
            <input type="date" value={filtros.desde} onChange={filtro('desde')} />
          </Campo>
          <Campo etiqueta="Hasta">
            <input type="date" value={filtros.hasta} onChange={filtro('hasta')} />
          </Campo>
        </div>
        <Aviso tipo="error">{error ?? errorExportar}</Aviso>
        <div className="tabla-caja">
          <table className="tabla">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Usuario</th>
                <th>Acción</th>
                <th>Dónde</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {datos?.data.map((r) => (
                <Fragment key={r.id}>
                  <tr>
                    <td>{fechaHora(r.fecha)}</td>
                    <td>{r.usuario}</td>
                    <td>{r.accion_nombre}</td>
                    <td>
                      <small className="ayuda">
                        {r.tabla}
                        {r.registro_id ? ` #${r.registro_id}` : ''}
                      </small>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <button type="button" className="boton boton-chico boton-borde" aria-expanded={abierto === r.id} onClick={() => setAbierto(abierto === r.id ? null : r.id)}>
                        {abierto === r.id ? 'Ocultar' : 'Ver cambios'}
                      </button>
                    </td>
                  </tr>
                  {abierto === r.id && (
                    <tr>
                      <td colSpan={5}>
                        <Cambios r={r} />
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
          {!cargando && datos?.data.length === 0 && <p className="vacio">No hay cambios con esos filtros.</p>}
          {cargando && <p className="vacio">Cargando…</p>}
        </div>
        {datos && datos.last_page > 1 && (
          <div className="paginas">
            <span>
              Página {datos.current_page} de {datos.last_page} · {datos.total} registros
            </span>
            <button type="button" className="boton boton-chico boton-borde" disabled={pagina <= 1} onClick={() => setPagina(pagina - 1)}>
              Anterior
            </button>
            <button type="button" className="boton boton-chico boton-borde" disabled={pagina >= datos.last_page} onClick={() => setPagina(pagina + 1)}>
              Siguiente
            </button>
          </div>
        )}
      </div>
    </>
  );
}
