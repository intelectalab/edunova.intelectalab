// Lógica sin interfaz de la tienda en la app (EDUN-181), probada con Vitest.

/** Total del carrito con los precios del catálogo (ignora productos que ya no están). */
export function totalCarrito(productos: { id: number; precio: number }[], carrito: Record<number, number>): number {
  return Math.round(Object.entries(carrito).reduce((s, [id, n]) => s + (productos.find((p) => p.id === Number(id))?.precio ?? 0) * n, 0) * 100) / 100;
}
