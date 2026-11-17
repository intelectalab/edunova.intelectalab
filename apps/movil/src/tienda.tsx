import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, Text, View } from 'react-native';
import type { Cliente } from './api';
import { totalCarrito } from './tiendaLogica';
import { Boton, colores, estilos, MensajeError } from './ui';

type Producto = { id: number; nombre: string; precio: number; categoria: string; stock: number; disponible: number };
type Pedido = { id: number; numero: string; total: number; estado: string };
const soles = (n: number) => `S/ ${n.toFixed(2)}`;

/** EDUN-181 · La familia compra (opcional) desde la app: paga en línea o con la pensión y recoge en secretaría. */
export function TiendaApp({ api, color }: { api: Cliente; color: string }) {
  const [productos, setProductos] = useState<Producto[]>([]);
  const [pedidos, setPedidos] = useState<Pedido[]>([]);
  const [hijo, setHijo] = useState<number | null>(null);
  const [carrito, setCarrito] = useState<Record<number, number>>({});
  const [abierta, setAbierta] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const cargar = useCallback(() => {
    api.pedir<{ productos: Producto[]; pedidos: Pedido[] }>('/tienda/familia').then((r) => { setProductos(r.productos); setPedidos(r.pedidos); }).catch(() => setProductos([]));
  }, [api]);
  useEffect(() => {
    cargar();
    api.pedir<{ hijos: { alumno_id?: number; id?: number }[] }>('/me/familia').then((r) => setHijo(Number(r.hijos?.[0]?.alumno_id ?? r.hijos?.[0]?.id) || null)).catch(() => undefined);
  }, [api, cargar]);
  if (!productos.length) return null;
  const total = totalCarrito(productos, carrito);
  const comprar = async (forma: 'inmediato' | 'con_pension') => {
    if (!hijo) return;
    setEnviando(true);
    setError(null);
    try {
      const items = Object.entries(carrito).filter(([, n]) => n > 0).map(([id, n]) => ({ producto_id: Number(id), cantidad: n }));
      const r = await api.pedir<{ id: number; numero: string }>('/tienda/familia/pedidos', { metodo: 'POST', cuerpo: { alumno_id: hijo, forma_pago: forma, items } });
      setCarrito({});
      if (forma === 'inmediato') await Linking.openURL((await api.pedir<{ url: string }>(`/tienda/familia/pedidos/${r.id}/pago`, { metodo: 'POST' })).url);
      setMensaje(forma === 'inmediato' ? `Pedido ${r.numero}: completa el pago; lo recoges en secretaría.` : `Pedido ${r.numero}: el cargo aparece en Mis pagos. Lo recoges en secretaría.`);
      setAbierta(false);
      cargar();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo hacer el pedido.');
    } finally {
      setEnviando(false);
    }
  };
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Tienda del colegio</Text>
      <Text style={estilos.subtitulo}>Comprar aquí es opcional.</Text>
      {!abierta ? <Boton titulo="Ver productos" borde color={color} alPulsar={() => setAbierta(true)} /> : (
        <>
          {productos.map((p) => {
            const hay = p.categoria === 'pack' ? p.disponible : p.stock;
            const n = carrito[p.id] ?? 0;
            return (
              <View key={p.id} style={estilos.fila}>
                <Text style={{ flex: 1, color: colores.texto }}>{p.nombre} · {soles(p.precio)}{hay ? '' : ' (agotado)'}</Text>
                <Pressable accessibilityRole="button" accessibilityLabel={`Quitar ${p.nombre}`} onPress={() => setCarrito({ ...carrito, [p.id]: Math.max(0, n - 1) })} style={{ padding: 8 }}><Text style={{ fontSize: 20, color }}>−</Text></Pressable>
                <Text style={{ minWidth: 20, textAlign: 'center' }}>{n}</Text>
                <Pressable accessibilityRole="button" accessibilityLabel={`Agregar ${p.nombre}`} disabled={n >= hay} onPress={() => setCarrito({ ...carrito, [p.id]: n + 1 })} style={{ padding: 8, opacity: n >= hay ? 0.4 : 1 }}><Text style={{ fontSize: 20, color }}>+</Text></Pressable>
              </View>
            );
          })}
          <Text style={{ fontWeight: '700' }}>Total {soles(total)}</Text>
          <Boton titulo="Pagar ahora (Yape o tarjeta)" color={color} cargando={enviando} deshabilitado={!total} alPulsar={() => comprar('inmediato')} />
          <Boton titulo="Con la pensión (cargo aparte)" borde color={color} deshabilitado={!total || enviando} alPulsar={() => comprar('con_pension')} />
        </>
      )}
      {pedidos.slice(0, 3).map((p) => <Text key={p.id} style={estilos.subtitulo}>{p.numero} · {soles(p.total)} · {p.estado}</Text>)}
      {mensaje && <Text style={estilos.subtitulo}>{mensaje}</Text>}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}
