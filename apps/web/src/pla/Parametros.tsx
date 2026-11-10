import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fecha, useAccion, useCarga } from '../panel/utilidades';

type Parametro = { id: number; codigo: string; valor: string; vigente_desde: string; fuente: string; confirmado: boolean; vigente: boolean };

/** EDUN-30 · PLA-06 Parámetros legales con fuente y vigencia; los no confirmados son alerta. */
export function ParametrosLegales() {
  const { pedir, estado } = useSesion();
  const esSuperadmin = estado!.roles_plataforma.includes('superadmin');
  const { datos, setDatos, error } = useCarga(() => pedir<{ data: Parametro[]; sin_confirmar: number }>('/plataforma/parametros-legales'));
  const accion = useAccion();
  const [f, setF] = useState({ codigo: '', valor: '', vigente_desde: '', fuente: '', confirmado: false });

  return (
    <>
      <Titulo migas="Plataforma" titulo="Parámetros legales" descripcion="Valores normativos que cambian con el tiempo, cada uno con su fuente y fecha de vigencia." />
      {datos && datos.sin_confirmar > 0 && <Aviso tipo="ambar">Hay {datos.sin_confirmar} valores vigentes sin confirmar por un abogado o la norma.</Aviso>}
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <div className="tarjeta" style={{ marginTop: 12 }}>
        <div className="tabla-caja">
          <table className="tabla">
            <thead>
              <tr>
                <th>Código</th>
                <th>Valor</th>
                <th>Desde</th>
                <th>Fuente</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              {datos?.data.map((p) => (
                <tr key={p.id} style={{ opacity: p.vigente ? 1 : 0.6 }}>
                  <td><code>{p.codigo}</code>{p.vigente && <span className="insignia insignia-azul" style={{ marginLeft: 6 }}>Vigente</span>}</td>
                  <td>{p.valor}</td>
                  <td>{fecha(p.vigente_desde)}</td>
                  <td><small>{p.fuente}</small></td>
                  <td>
                    {p.confirmado ? <span className="insignia insignia-ok">Confirmado</span> : <span className="insignia insignia-ambar">Por confirmar</span>}{' '}
                    {esSuperadmin && (
                      <button type="button" className="boton boton-chico boton-borde" disabled={accion.enviando}
                        onClick={() => accion.ejecutar(async () => setDatos(await pedir(`/plataforma/parametros-legales/${p.id}`, { metodo: 'PATCH', cuerpo: { confirmado: !p.confirmado } })))}>
                        {p.confirmado ? 'Desmarcar' : 'Confirmar'}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
      {esSuperadmin && (
        <div className="tarjeta" style={{ marginTop: 18, maxWidth: 900 }}>
          <h2>Nuevo valor</h2>
          <p className="ayuda">Para cambiar un valor, registra uno nuevo con su fecha de vigencia: el anterior queda como historia.</p>
          <div className="campos">
            <Campo etiqueta="Código"><input value={f.codigo} placeholder="uit.valor" onChange={(e) => setF({ ...f, codigo: e.target.value })} /></Campo>
            <Campo etiqueta="Valor"><input value={f.valor} onChange={(e) => setF({ ...f, valor: e.target.value })} /></Campo>
            <Campo etiqueta="Vigente desde"><input type="date" value={f.vigente_desde} onChange={(e) => setF({ ...f, vigente_desde: e.target.value })} /></Campo>
            <Campo etiqueta="Fuente" ancho><input value={f.fuente} placeholder="Norma, decreto o resolución" onChange={(e) => setF({ ...f, fuente: e.target.value })} /></Campo>
          </div>
          <label className="casilla" style={{ marginTop: 8 }}>
            <input type="checkbox" checked={f.confirmado} onChange={(e) => setF({ ...f, confirmado: e.target.checked })} /> Confirmado
          </label>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando}
              onClick={() => accion.ejecutar(async () => { setDatos(await pedir('/plataforma/parametros-legales', { metodo: 'POST', cuerpo: f })); setF({ codigo: '', valor: '', vigente_desde: '', fuente: '', confirmado: false }); }, 'Valor guardado.')}>
              Guardar
            </button>
          </div>
          <Aviso tipo="ok">{accion.ok}</Aviso>
        </div>
      )}
    </>
  );
}
