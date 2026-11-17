import { describe, expect, it } from 'vitest';
import { totalCarrito } from './tiendaLogica';

describe('tienda en la app (EDUN-181)', () => {
  it('suma el carrito con los precios del catálogo', () => {
    expect(totalCarrito([{ id: 1, precio: 5.5 }, { id: 2, precio: 40 }], { 1: 3, 2: 1 })).toBe(56.5);
    expect(totalCarrito([{ id: 1, precio: 5 }], { 9: 2 })).toBe(0);
  });
});
