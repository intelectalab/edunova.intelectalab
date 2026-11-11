import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { useAccion, useCarga } from '../panel/utilidades';
import type { Proveedor } from './tipos';

type Datos = { data: Proveedor[]; catalogo: Record<string, string> };

function FormProveedor({ inicial, catalogo, alGuardar }: { inicial: Partial<Proveedor>; catalogo: Record<string, string>; alGuardar: (d: Proveedor[]) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState({
    proveedor: inicial.proveedor ?? Object.keys(catalogo)[0] ?? '', ambiente: inicial.ambiente ?? 'beta',
    serie_boleta: inicial.serie_boleta ?? 'B001', serie_factura: inicial.serie_factura ?? 'F001', serie_nota_credito: inicial.serie_nota_credito ?? '',
    predeterminado: inicial.predeterminado ?? false, activo: inicial.activo ?? true, credenciales: '',
  });

  async function enviar(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => {
      const r = await pedir<{ data: Proveedor[] }>(inicial.id ? `/pagos/proveedores-facturacion/${inicial.id}` : '/pagos/proveedores-facturacion', {
        metodo: inicial.id ? 'PUT' : 'POST', cuerpo: { ...f, credenciales: f.credenciales || null, serie_nota_credito: f.serie_nota_credito || null },
      });
      alGuardar(r.data);
    });
  }

  return (
    <form onSubmit={enviar} noValidate>
      <div className="campos">
        <Campo etiqueta="Proveedor">
          <select value={f.proveedor} disabled={!!inicial.id} onChange={(e) => setF({ ...f, proveedor: e.target.value })}>
            {Object.entries(catalogo).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Campo>
        <Campo etiqueta="Ambiente">
          <select value={f.ambiente} onChange={(e) => setF({ ...f, ambiente: e.target.value as Proveedor['ambiente'] })}>
            <option value="beta">Beta (pruebas, sin valor tributario)</option>
            <option value="produccion">Producción</option>
          </select>
        </Campo>
        <Campo etiqueta="Serie de boletas"><input value={f.serie_boleta} maxLength={4} onChange={(e) => setF({ ...f, serie_boleta: e.target.value.toUpperCase() })} /></Campo>
        <Campo etiqueta="Serie de facturas"><input value={f.serie_factura} maxLength={4} onChange={(e) => setF({ ...f, serie_factura: e.target.value.toUpperCase() })} /></Campo>
        <Campo etiqueta="Serie de notas de crédito"><input value={f.serie_nota_credito} maxLength={4} placeholder="BC01" onChange={(e) => setF({ ...f, serie_nota_credito: e.target.value.toUpperCase() })} /></Campo>
      </div>
      <Campo etiqueta="Credenciales" ayuda={inicial.tiene_credenciales ? 'Guardadas y cifradas. Escribe solo si quieres reemplazarlas.' : 'Usuario, clave o token del proveedor. Se guardan cifradas y no se vuelven a mostrar.'} ancho>
        <textarea className="control" autoComplete="off" style={{ width: '100%', height: 80, padding: 10 }} value={f.credenciales} onChange={(e) => setF({ ...f, credenciales: e.target.value })} />
      </Campo>
      <label className="casilla"><input type="checkbox" checked={f.predeterminado} onChange={(e) => setF({ ...f, predeterminado: e.target.checked })} /> Predeterminado (con este se emiten los comprobantes)</label>
      <label className="casilla"><input type="checkbox" checked={f.activo} onChange={(e) => setF({ ...f, activo: e.target.checked })} /> Activo</label>
      {f.ambiente === 'produccion' && <Aviso tipo="ambar">En producción los comprobantes se envían a SUNAT con valor tributario.</Aviso>}
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones"><button type="submit" className="boton" disabled={accion.enviando}>{accion.enviando ? 'Guardando…' : 'Guardar'}</button></div>
    </form>
  );
}

/** EDUN-90 · PAG-H16 Proveedores de facturación electrónica. */
export function ProveedoresFacturacion() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const editable = puede(colegio, 'pag.comprobantes.emitir');
  const { datos, setDatos, error } = useCarga(() => pedir<Datos>('/pagos/proveedores-facturacion'));
  const [editando, setEditando] = useState<Partial<Proveedor> | null>(null);
  const libres = datos ? Object.keys(datos.catalogo).filter((k) => !datos.data.some((p) => p.proveedor === k)) : [];

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Facturación electrónica" descripcion="Proveedor con el que se emiten boletas, facturas y notas de crédito, y sus series.">
        {editable && libres.length > 0 && <button type="button" className="boton" onClick={() => setEditando({ proveedor: libres[0] })}>+ Proveedor</button>}
      </Titulo>
      <Aviso tipo="error">{error}</Aviso>
      {datos && datos.data.length > 0 && !datos.data.some((p) => p.predeterminado) && <Aviso tipo="ambar">Ningún proveedor activo está marcado como predeterminado.</Aviso>}
      <div className="tarjeta">
        <div className="tabla-caja">
          <table className="tabla">
            <thead><tr><th>Proveedor</th><th>Ambiente</th><th>Boletas</th><th>Facturas</th><th>Notas de crédito</th><th>Estado</th><th /></tr></thead>
            <tbody>
              {datos?.data.map((p) => (
                <tr key={p.id} style={{ opacity: p.activo ? 1 : 0.55 }}>
                  <td><b>{p.nombre}</b> {p.predeterminado && <span className="insignia insignia-ok">Predeterminado</span>}</td>
                  <td>{p.ambiente === 'produccion' ? 'Producción' : <span className="insignia insignia-ambar">Beta</span>}</td>
                  <td><code>{p.serie_boleta}</code></td>
                  <td><code>{p.serie_factura}</code></td>
                  <td>{p.serie_nota_credito ? <code>{p.serie_nota_credito}</code> : '—'}</td>
                  <td>{p.activo ? 'Activo' : 'Inactivo'}{!p.tiene_credenciales && ' · sin credenciales'}</td>
                  <td>{editable && <button type="button" className="boton boton-chico boton-borde" onClick={() => setEditando(p)}>Editar</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos?.data.length === 0 && <p className="vacio">Aún no hay proveedor de facturación configurado.</p>}
        </div>
      </div>
      {editando && datos && (
        <Dialogo titulo={editando.id ? `Editar ${editando.nombre}` : 'Nuevo proveedor'} alCerrar={() => setEditando(null)}>
          <FormProveedor inicial={editando} catalogo={editando.id ? datos.catalogo : Object.fromEntries(libres.map((k) => [k, datos.catalogo[k]]))}
            alGuardar={(d) => { setDatos({ ...datos, data: d }); setEditando(null); }} />
        </Dialogo>
      )}
    </>
  );
}
