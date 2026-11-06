import { describe, expect, it } from 'vitest';
import { archivoHeaders, cabecerasSeguridad, politicaContenido } from '../seguridad.ts';

// EDUN-209 · Cabeceras de seguridad de la web de gestión.

describe('cabeceras de seguridad', () => {
  it('en producción: CSP estricta, HSTS y protección contra clickjacking', () => {
    const h = cabecerasSeguridad('https://api.edunova.pe/api/v1', false);
    expect(h['Content-Security-Policy']).toContain("frame-ancestors 'none'");
    expect(h['Content-Security-Policy']).toContain('connect-src \'self\' https://api.edunova.pe');
    expect(h['Content-Security-Policy']).not.toContain("'unsafe-inline' https://fonts.gstatic.com");
    expect(politicaContenido('https://api.edunova.pe/api/v1', false)).toMatch(/script-src 'self';/);
    expect(h['X-Frame-Options']).toBe('DENY');
    expect(h['X-Content-Type-Options']).toBe('nosniff');
    expect(h['Strict-Transport-Security']).toContain('max-age=31536000');
  });

  it('en desarrollo permite la recarga de Vite y no envía HSTS', () => {
    const h = cabecerasSeguridad(undefined, true);
    expect(h['Content-Security-Policy']).toContain('ws:');
    expect(h['Strict-Transport-Security']).toBeUndefined();
  });

  it('el archivo _headers del hosting aplica a todas las rutas', () => {
    const archivo = archivoHeaders('https://api.edunova.pe/api/v1');
    expect(archivo.startsWith('/*\n')).toBe(true);
    expect(archivo).toContain('  X-Frame-Options: DENY');
  });
});
