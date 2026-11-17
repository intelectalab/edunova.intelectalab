import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Pestanas, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Producto = { id: number; codigo: string; nombre: string; categoria: string; nivel: string; precio: number; afectacion_igv: string; stock: number; stock_minimo: number; disponible: number; visible_app: boolean; bajo_minimo: boolean; contenido: { producto_id: number; nombre: string; cantidad: number }[] | null };
type Pedido = { id: number; numero: string; canal: string; total: number; forma_pago: string; estado: string; alumno: string; created_at: string; entregado_a: string | null; reservado_hasta: string | null; items: { nombre: string; cantidad: number; precio: number }[] };
type Lista = { id: number; grado_id: number; grado: string; nivel: string; pack: string | null; pack_precio: number | null; entrega_gradual: boolean; publicada_at: string | null; items: { descripcion: string; cantidad: number; area: string | null }[] };
const CATEGORIA: Record<string, string> = { util: 'Útil', uniforme: 'Uniforme', texto: 'Texto', pack: 'Pack', otro: 'Otro' };
const IGV: Record<string, string> = { '10': 'Gravado', '20': 'Exonerado', '30': 'Inafecto' };
const ESTADO: Record<string, string> = { pendiente: 'insignia-ambar', pagado: 'insignia-azul', preparado: 'insignia-azul', entregado: 'insignia-ok', anulado: 'insignia-gris' };
const soles = (n: number) => `S/ ${n.toFixed(2)}`;

/** EDUN-179 a 185 · Tienda escolar: productos, listas de útiles, pedidos, venta en caja e informes. */
export function Tienda() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const opciones: ['productos' | 'listas' | 'pedidos' | 'caja' | 'reportes', string][] = [];
  if (puede(colegio, 'tie.productos.ver')) opciones.push(['productos', 'Productos e inventario'], ['listas', 'Listas de útiles']);
  if (puede(colegio, 'tie.ventas.ver')) opciones.push(['pedidos', 'Pedidos']);
  if (puede(colegio, 'tie.ventas.vender')) opciones.push(['caja', 'Venta en caja']);
  if (puede(colegio, 'tie.ventas.ver')) opciones.push(['reportes', 'Reportes']);
  const [pestana, setPestana] = useState(opciones[0]?.[0] ?? 'pedidos');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Servicios`} titulo="Tienda escolar" descripcion="Comprar en la tienda del colegio es siempre voluntario. El stock nunca queda negativo y cada movimiento queda registrado." />
      <Pestanas opciones={opciones} actual={pestana} alCambiar={setPestana} />
      {pestana === 'productos' && <Productos />}
      {pestana === 'listas' && <Listas />}
      {pestana === 'pedidos' && <Pedidos />}
      {pestana === 'caja' && <VentaCaja />}
      {pestana === 'reportes' && <Reportes />}
    </>
  );
}

function Productos() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Producto[] }>('/tienda/productos'));
  const [nuevo, setNuevo] = useState<{ codigo: string; nombre: string; categoria: string; precio: string; afectacion_igv: string; stock_minimo: string; contenido: { producto_id: number; cantidad: number }[] } | null>(null);
  const [mov, setMov] = useState<Producto | null>(null);
  const [kardex, setKardex] = useState<{ p: Producto; filas: { id: number; tipo: string; cantidad: number; proveedor: string | null; created_at: string; numero: string | null }[] } | null>(null);
  const [m, setM] = useState({ tipo: 'ingreso', cantidad: '', costo_unitario: '', proveedor: '', motivo: '' });
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {puede(colegio, 'tie.productos.editar') && <div className="acciones"><button type="button" className="boton" onClick={() => setNuevo({ codigo: '', nombre: '', categoria: 'util', precio: '', afectacion_igv: '10', stock_minimo: '0', contenido: [] })}>Nuevo producto o pack</button></div>}
      {datos && <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Producto</th><th>Precio</th><th>IGV</th><th>Stock</th><th /></tr></thead><tbody>
        {datos.data.map((p) => (
          <tr key={p.id}>
            <td>{p.nombre}<br /><small className="ayuda">{p.codigo} · {CATEGORIA[p.categoria]}{p.contenido ? ` · ${p.contenido.map((c) => `${c.cantidad} ${c.nombre}`).join(', ')}` : ''}</small></td>
            <td>{soles(p.precio)}</td><td>{IGV[p.afectacion_igv] ?? p.afectacion_igv}</td>
            <td>{p.categoria === 'pack' ? `${p.disponible} armables` : p.stock}{p.bajo_minimo && <> <span className="insignia insignia-ambar">Bajo mínimo</span></>}</td>
            <td><div className="acciones">{p.categoria !== 'pack' && puede(colegio, 'tie.inventario.ajustar') && <button type="button" className="boton boton-borde boton-chico" onClick={() => setMov(p)}>Stock</button>}
              {p.categoria !== 'pack' && puede(colegio, 'tie.inventario.ver') && <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => setKardex({ p, filas: (await pedir<{ data: { id: number; tipo: string; cantidad: number; proveedor: string | null; created_at: string; numero: string | null }[] }>(`/tienda/productos/${p.id}/kardex`)).data }))}>Movimientos</button>}</div></td>
          </tr>
        ))}
      </tbody></table></div></div>}
      {nuevo && datos && (
        <Dialogo titulo="Nuevo producto" alCerrar={() => setNuevo(null)}>
          <Aviso tipo="error">{accion.error}</Aviso>
          <div className="campos">
            <Campo etiqueta="Código"><input value={nuevo.codigo} onChange={(e) => setNuevo({ ...nuevo, codigo: e.target.value })} /></Campo>
            <Campo etiqueta="Nombre"><input value={nuevo.nombre} onChange={(e) => setNuevo({ ...nuevo, nombre: e.target.value })} /></Campo>
            <Campo etiqueta="Categoría"><select value={nuevo.categoria} onChange={(e) => setNuevo({ ...nuevo, categoria: e.target.value })}>{Object.entries(CATEGORIA).map(([v, n]) => <option key={v} value={v}>{n}</option>)}</select></Campo>
            <Campo etiqueta="Precio"><input type="number" step="0.01" value={nuevo.precio} onChange={(e) => setNuevo({ ...nuevo, precio: e.target.value })} /></Campo>
            <Campo etiqueta="IGV" ayuda="Valídalo con el contador."><select value={nuevo.afectacion_igv} onChange={(e) => setNuevo({ ...nuevo, afectacion_igv: e.target.value })}>{Object.entries(IGV).map(([v, n]) => <option key={v} value={v}>{n}</option>)}</select></Campo>
            {nuevo.categoria !== 'pack' && <Campo etiqueta="Stock mínimo"><input type="number" value={nuevo.stock_minimo} onChange={(e) => setNuevo({ ...nuevo, stock_minimo: e.target.value })} /></Campo>}
          </div>
          {nuevo.categoria === 'pack' && <><h3>Contenido del pack</h3>{datos.data.filter((p) => p.categoria !== 'pack').map((p) => {
            const c = nuevo.contenido.find((x) => x.producto_id === p.id);
            return <div key={p.id} className="filtros"><span>{p.nombre}</span><input aria-label={`Cantidad de ${p.nombre}`} type="number" min={0} value={c?.cantidad ?? 0} onChange={(e) => { const n = Number(e.target.value); setNuevo({ ...nuevo, contenido: [...nuevo.contenido.filter((x) => x.producto_id !== p.id), ...(n > 0 ? [{ producto_id: p.id, cantidad: n }] : [])] }); }} /></div>;
          })}</>}
          <button type="button" className="boton" disabled={accion.enviando || !nuevo.codigo || nuevo.nombre.length < 2 || !nuevo.precio} onClick={() => accion.ejecutar(async () => { await pedir('/tienda/productos', { metodo: 'POST', cuerpo: { ...nuevo, precio: Number(nuevo.precio), stock_minimo: Number(nuevo.stock_minimo) } }); setNuevo(null); recargar(); })}>Guardar</button>
        </Dialogo>
      )}
      {mov && (
        <Dialogo titulo={`Stock: ${mov.nombre} (hay ${mov.stock})`} alCerrar={() => setMov(null)}>
          <Aviso tipo="error">{accion.error}</Aviso>
          <div className="campos">
            <Campo etiqueta="Tipo"><select value={m.tipo} onChange={(e) => setM({ ...m, tipo: e.target.value })}><option value="ingreso">Ingreso (compra)</option><option value="ajuste">Ajuste (+ o −)</option></select></Campo>
            <Campo etiqueta="Cantidad"><input type="number" value={m.cantidad} onChange={(e) => setM({ ...m, cantidad: e.target.value })} /></Campo>
            {m.tipo === 'ingreso' ? <><Campo etiqueta="Costo unitario"><input type="number" step="0.01" value={m.costo_unitario} onChange={(e) => setM({ ...m, costo_unitario: e.target.value })} /></Campo><Campo etiqueta="Proveedor"><input value={m.proveedor} onChange={(e) => setM({ ...m, proveedor: e.target.value })} /></Campo></>
              : <Campo etiqueta="Motivo"><input value={m.motivo} onChange={(e) => setM({ ...m, motivo: e.target.value })} /></Campo>}
          </div>
          <button type="button" className="boton" disabled={accion.enviando || !m.cantidad} onClick={() => accion.ejecutar(async () => { await pedir(`/tienda/productos/${mov.id}/movimientos`, { metodo: 'POST', cuerpo: { tipo: m.tipo, cantidad: Number(m.cantidad), costo_unitario: m.costo_unitario ? Number(m.costo_unitario) : null, proveedor: m.proveedor || null, motivo: m.motivo || null } }); setMov(null); setM({ tipo: 'ingreso', cantidad: '', costo_unitario: '', proveedor: '', motivo: '' }); recargar(); })}>Registrar</button>
        </Dialogo>
      )}
      {kardex && <Dialogo titulo={`Movimientos: ${kardex.p.nombre}`} alCerrar={() => setKardex(null)}><table className="tabla"><thead><tr><th>Fecha</th><th>Tipo</th><th>Cantidad</th><th>Detalle</th></tr></thead><tbody>{kardex.filas.map((k) => <tr key={k.id}><td>{fechaHora(k.created_at)}</td><td>{k.tipo}</td><td>{k.cantidad > 0 ? `+${k.cantidad}` : k.cantidad}</td><td>{k.numero ?? k.proveedor ?? ''}</td></tr>)}</tbody></table></Dialogo>}
    </>
  );
}

function Listas() {
  const { pedir } = useSesion();
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Lista[]; anios: { id: number; anio: number }[]; grados: { id: number; nombre: string; nivel: string }[] }>('/tienda/listas'));
  const productos = useCarga(() => pedir<{ data: Producto[] }>('/tienda/productos'));
  const [edit, setEdit] = useState<{ grado_id: string; anio_escolar_id: string; pack_id: string; entrega_gradual: boolean; texto: string } | null>(null);
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="acciones"><button type="button" className="boton" onClick={() => setEdit({ grado_id: '', anio_escolar_id: String(datos?.anios[0]?.id ?? ''), pack_id: '', entrega_gradual: true, texto: '' })}>Lista de un grado</button></div>
      {datos && datos.data.map((l) => (
        <div key={l.id} className="tarjeta">
          <div className="titulo-fila"><h2>{l.grado} ({l.nivel})</h2>{l.publicada_at ? <span className="insignia insignia-ok">Publicada</span> : <button type="button" className="boton boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/tienda/listas/${l.id}/publicacion`, { metodo: 'POST' }); recargar(); accion.setOk('Publicada: las familias del grado recibieron el aviso.'); })}>Publicar</button>}</div>
          {l.pack && <p>Pack opcional: {l.pack}{l.pack_precio !== null ? ` · ${soles(l.pack_precio)}` : ''}{l.entrega_gradual ? ' · entrega gradual' : ''}</p>}
          <ul className="lista-simple">{l.items.map((i, k) => <li key={k}>{i.cantidad} × {i.descripcion}{i.area ? ` (${i.area})` : ''}</li>)}</ul>
        </div>
      ))}
      {edit && datos && (
        <Dialogo titulo="Lista de útiles" alCerrar={() => setEdit(null)}>
          <div className="campos">
            <Campo etiqueta="Año"><select value={edit.anio_escolar_id} onChange={(e) => setEdit({ ...edit, anio_escolar_id: e.target.value })}>{datos.anios.map((a) => <option key={a.id} value={a.id}>{a.anio}</option>)}</select></Campo>
            <Campo etiqueta="Grado"><select value={edit.grado_id} onChange={(e) => setEdit({ ...edit, grado_id: e.target.value })}><option value="">Elige…</option>{datos.grados.map((g) => <option key={g.id} value={g.id}>{g.nombre} ({g.nivel})</option>)}</select></Campo>
            <Campo etiqueta="Pack opcional"><select value={edit.pack_id} onChange={(e) => setEdit({ ...edit, pack_id: e.target.value })}><option value="">Sin pack</option>{(productos.datos?.data ?? []).filter((p) => p.categoria === 'pack').map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}</select></Campo>
            <label className="campo"><span><input type="checkbox" checked={edit.entrega_gradual} onChange={(e) => setEdit({ ...edit, entrega_gradual: e.target.checked })} /> Entrega gradual durante el año</span></label>
            <Campo etiqueta="Útiles (uno por línea: «2 Cuaderno cuadriculado | Matemática»)" ancho><textarea rows={8} value={edit.texto} onChange={(e) => setEdit({ ...edit, texto: e.target.value })} /></Campo>
          </div>
          <button type="button" className="boton" disabled={!edit.grado_id || accion.enviando} onClick={() => accion.ejecutar(async () => {
            const items = edit.texto.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => { const [desc, area] = l.split('|').map((x) => x.trim()); const m = desc.match(/^(\d+)\s+(.*)$/); return { cantidad: m ? Number(m[1]) : 1, descripcion: m ? m[2] : desc, area: area || null }; });
            await pedir('/tienda/listas', { metodo: 'PUT', cuerpo: { grado_id: Number(edit.grado_id), anio_escolar_id: Number(edit.anio_escolar_id), pack_id: edit.pack_id ? Number(edit.pack_id) : null, entrega_gradual: edit.entrega_gradual, items } });
            setEdit(null);
            recargar();
          })}>Guardar</button>
        </Dialogo>
      )}
    </>
  );
}

function Pedidos() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [filtro, setFiltro] = useState('');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Pedido[] }>(`/tienda/pedidos${filtro ? `?estado=${filtro}` : ''}`), [filtro]);
  const accion = useAccion();
  const mover = (p: Pedido, a: string) => {
    const entregado_a = a === 'entregar' ? window.prompt('¿A quién se entrega?') : null;
    if (a === 'entregar' && !entregado_a?.trim()) return;
    if (a === 'anular' && !window.confirm(`¿Anular el pedido ${p.numero}? El stock vuelve.`)) return;
    void accion.ejecutar(async () => { await pedir(`/tienda/pedidos/${p.id}/estado`, { metodo: 'POST', cuerpo: { accion: a, entregado_a } }); recargar(); });
  };
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <div className="filtros"><Campo etiqueta="Estado"><select value={filtro} onChange={(e) => setFiltro(e.target.value)}><option value="">Todos</option>{Object.keys(ESTADO).map((e) => <option key={e}>{e}</option>)}</select></Campo></div>
      {datos && <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Pedido</th><th>Alumno</th><th>Productos</th><th>Total</th><th>Estado</th><th /></tr></thead><tbody>
        {datos.data.map((p) => (
          <tr key={p.id}>
            <td>{p.numero}<br /><small className="ayuda">{p.canal === 'app' ? 'App' : 'Caja'} · {fechaHora(p.created_at)}</small></td>
            <td>{p.alumno}</td>
            <td>{p.items.map((i) => `${i.cantidad} ${i.nombre}`).join(', ')}</td>
            <td>{soles(p.total)}<br /><small className="ayuda">{p.forma_pago === 'con_pension' ? 'Con la pensión' : 'Pago inmediato'}</small></td>
            <td><span className={`insignia ${ESTADO[p.estado]}`}>{p.estado}</span>{p.estado === 'pendiente' && p.reservado_hasta && <><br /><small className="ayuda">Reserva hasta {fechaHora(p.reservado_hasta)}</small></>}{p.entregado_a && <><br /><small className="ayuda">A {p.entregado_a}</small></>}</td>
            <td><div className="acciones">
              {p.estado === 'pagado' && puede(colegio, 'tie.ventas.entregar') && <button type="button" className="boton boton-borde boton-chico" onClick={() => mover(p, 'preparar')}>Preparado</button>}
              {['pagado', 'preparado'].includes(p.estado) && puede(colegio, 'tie.ventas.entregar') && <button type="button" className="boton boton-chico" onClick={() => mover(p, 'entregar')}>Entregar</button>}
              {['pendiente', 'pagado', 'preparado'].includes(p.estado) && puede(colegio, 'tie.ventas.anular') && <button type="button" className="boton boton-borde boton-chico" onClick={() => mover(p, 'anular')}>Anular</button>}
            </div></td>
          </tr>
        ))}
      </tbody></table></div></div>}
    </>
  );
}

function VentaCaja() {
  const { pedir } = useSesion();
  const productos = useCarga(() => pedir<{ data: Producto[] }>('/tienda/productos'));
  const [carrito, setCarrito] = useState<Record<number, number>>({});
  const [buscar, setBuscar] = useState('');
  const alumnos = useCarga(() => (buscar.trim().length >= 3 ? pedir<{ data: { id: number; nombres: string; apellido_paterno: string }[] }>(`/alumnos?buscar=${encodeURIComponent(buscar.trim())}`) : Promise.resolve({ data: [] })), [buscar]);
  const [alumno, setAlumno] = useState<{ id: number; nombre: string } | null>(null);
  const [medio, setMedio] = useState('efectivo');
  const accion = useAccion();
  const lista = productos.datos?.data ?? [];
  const total = Object.entries(carrito).reduce((s, [id, n]) => s + (lista.find((p) => p.id === Number(id))?.precio ?? 0) * n, 0);
  return (
    <>
      <Aviso tipo="error">{productos.error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tarjeta">
        <div className="filtros">
          <Campo etiqueta="Alumno (para la boleta)" ayuda={alumno ? `Boleta a la familia de ${alumno.nombre}.` : 'Sin alumno es venta libre (sin boleta electrónica).'}><input value={buscar} onChange={(e) => setBuscar(e.target.value)} /></Campo>
          {(alumnos.datos?.data ?? []).slice(0, 6).map((a) => <button key={a.id} type="button" className={`boton boton-chico ${alumno?.id === a.id ? '' : 'boton-borde'}`} onClick={() => setAlumno({ id: a.id, nombre: `${a.nombres} ${a.apellido_paterno}` })}>{a.apellido_paterno}, {a.nombres}</button>)}
          {alumno && <button type="button" className="enlace" onClick={() => setAlumno(null)}>Venta libre</button>}
        </div>
        <table className="tabla"><tbody>{lista.map((p) => <tr key={p.id}><td>{p.nombre}</td><td>{soles(p.precio)}</td><td>{p.categoria === 'pack' ? p.disponible : p.stock} disp.</td><td><input aria-label={`Cantidad de ${p.nombre}`} type="number" min={0} value={carrito[p.id] ?? 0} onChange={(e) => setCarrito({ ...carrito, [p.id]: Number(e.target.value) })} style={{ width: 70 }} /></td></tr>)}</tbody></table>
        <div className="filtros">
          <b>Total {soles(total)}</b>
          <Campo etiqueta="Medio"><select value={medio} onChange={(e) => setMedio(e.target.value)}><option value="efectivo">Efectivo</option><option value="yape">Yape</option><option value="tarjeta">Tarjeta</option><option value="transferencia">Transferencia</option></select></Campo>
          <button type="button" className="boton" disabled={!total || accion.enviando} onClick={() => accion.ejecutar(async () => {
            const items = Object.entries(carrito).filter(([, n]) => n > 0).map(([id, n]) => ({ producto_id: Number(id), cantidad: n }));
            const r = await pedir<{ pedido: string; comprobante_id: number | null }>('/tienda/ventas', { metodo: 'POST', cuerpo: { alumno_id: alumno?.id ?? null, items, medios: [{ medio, monto: Math.round(total * 100) / 100 }] } });
            setCarrito({});
            productos.recargar();
            accion.setOk(`Venta ${r.pedido} registrada${r.comprobante_id ? ' con boleta electrónica' : ' (venta libre)'}.`);
          })}>Cobrar</button>
        </div>
        <p className="ayuda">Necesitas una caja abierta (Pagos → Caja).</p>
      </div>
    </>
  );
}

function Reportes() {
  const { pedir } = useSesion();
  const [rango, setRango] = useState({ desde: '', hasta: '' });
  const q = new URLSearchParams(Object.entries(rango).filter(([, v]) => v) as [string, string][]).toString();
  const { datos, error } = useCarga(() => pedir<{ desde: string; hasta: string; ventas: number; pedidos: number; por_canal: { canal: string; total: number; pedidos: number }[]; mas_vendidos: { nombre: string; unidades: number; total: number }[]; bajo_minimo: Producto[]; por_entregar: number }>(`/tienda/reportes?${q}`), [q]);
  return (
    <>
      <Aviso tipo="error">{error}</Aviso>
      <div className="filtros"><Campo etiqueta="Desde"><input type="date" value={rango.desde} onChange={(e) => setRango({ ...rango, desde: e.target.value })} /></Campo><Campo etiqueta="Hasta"><input type="date" value={rango.hasta} onChange={(e) => setRango({ ...rango, hasta: e.target.value })} /></Campo></div>
      {datos && <>
        <div className="tarjeta"><h2>{fecha(datos.desde)} – {fecha(datos.hasta)}</h2><p>Ventas: <b>{soles(datos.ventas)}</b> en {datos.pedidos} pedido(s). Por entregar: {datos.por_entregar}.</p>
          <ul className="lista-simple">{datos.por_canal.map((c) => <li key={c.canal}>{c.canal === 'app' ? 'App' : 'Caja'}: {soles(c.total)} ({c.pedidos})</li>)}</ul></div>
        <div className="tarjeta"><h2>Más vendidos</h2><ol>{datos.mas_vendidos.map((m) => <li key={m.nombre}>{m.nombre}: {m.unidades} u. · {soles(m.total)}</li>)}</ol></div>
        {datos.bajo_minimo.length > 0 && <div className="tarjeta"><h2>Bajo el stock mínimo</h2><ul className="lista-simple">{datos.bajo_minimo.map((p) => <li key={p.id}>{p.nombre}: {p.stock} (mínimo {p.stock_minimo})</li>)}</ul></div>}
      </>}
    </>
  );
}

/** EDUN-180 / 181 · La familia ve la lista de útiles y compra (opcional) desde la web o la app. */
export function TiendaFamilia() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ productos: Producto[]; listas: Lista[]; pedidos: Pedido[]; aviso: string }>('/tienda/familia'));
  const familia = useCarga(() => pedir<{ hijos: { id: number; nombres: string }[] }>('/me/familia'));
  const [carrito, setCarrito] = useState<Record<number, number>>({});
  const [forma, setForma] = useState<'inmediato' | 'con_pension'>('inmediato');
  const accion = useAccion();
  const hijo = familia.datos?.hijos[0]?.id;
  const total = Object.entries(carrito).reduce((s, [id, n]) => s + (datos?.productos.find((p) => p.id === Number(id))?.precio ?? 0) * n, 0);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Tienda del colegio" descripcion={datos?.aviso ?? 'Comprar en la tienda del colegio es opcional.'} />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos?.listas.map((l) => <div key={l.id} className="tarjeta"><h2>Lista de útiles · {l.grado}</h2><ul className="lista-simple">{l.items.map((i, k) => <li key={k}>{i.cantidad} × {i.descripcion}</li>)}</ul>{l.pack && <p className="ayuda">Puedes comprar el pack «{l.pack}» abajo, o comprar los útiles donde prefieras.</p>}</div>)}
      {datos && <div className="tarjeta"><h2>Productos</h2>
        <table className="tabla"><tbody>{datos.productos.map((p) => <tr key={p.id}><td>{p.nombre}</td><td>{soles(p.precio)}</td><td>{(p.categoria === 'pack' ? p.disponible : p.stock) > 0 ? <input aria-label={`Cantidad de ${p.nombre}`} type="number" min={0} max={20} value={carrito[p.id] ?? 0} onChange={(e) => setCarrito({ ...carrito, [p.id]: Number(e.target.value) })} style={{ width: 70 }} /> : 'Agotado'}</td></tr>)}</tbody></table>
        <div className="filtros">
          <b>Total {soles(total)}</b>
          <Campo etiqueta="Pago"><select value={forma} onChange={(e) => setForma(e.target.value as typeof forma)}><option value="inmediato">Pagar ahora (Yape o tarjeta)</option><option value="con_pension">Con la pensión (cargo aparte)</option></select></Campo>
          <button type="button" className="boton" disabled={!total || !hijo || accion.enviando} onClick={() => accion.ejecutar(async () => {
            const items = Object.entries(carrito).filter(([, n]) => n > 0).map(([id, n]) => ({ producto_id: Number(id), cantidad: n }));
            const r = await pedir<{ id: number; numero: string; estado: string }>('/tienda/familia/pedidos', { metodo: 'POST', cuerpo: { alumno_id: hijo, forma_pago: forma, items } });
            setCarrito({});
            if (forma === 'inmediato') window.location.assign((await pedir<{ url: string }>(`/tienda/familia/pedidos/${r.id}/pago`, { metodo: 'POST' })).url);
            else { recargar(); accion.setOk(`Pedido ${r.numero} registrado: el cargo aparece en Mis pagos. Lo recoges en secretaría.`); }
          })}>Comprar</button>
        </div>
        <p className="ayuda">El pedido reserva los productos 48 horas. Se recoge en secretaría.</p>
      </div>}
      {datos && datos.pedidos.length > 0 && <div className="tarjeta"><h2>Mis pedidos</h2><ul className="lista-simple">{datos.pedidos.map((p) => <li key={p.id}>{p.numero} · {soles(p.total)} · <span className={`insignia ${ESTADO[p.estado]}`}>{p.estado}</span>{p.estado === 'pendiente' && <> <button type="button" className="enlace" onClick={() => accion.ejecutar(async () => window.location.assign((await pedir<{ url: string }>(`/tienda/familia/pedidos/${p.id}/pago`, { metodo: 'POST' })).url))}>Pagar</button></>}</li>)}</ul></div>}
    </>
  );
}
