import { beforeEach, describe, expect, it } from 'vitest';
import { canalContacto, limpiarParametros, registrarEvento, ubicacionDe, umbralesNuevos } from './analitica';

// EDUN-318 · Eventos de analítica en el dataLayer, sin datos personales.
describe('analítica', () => {
  beforeEach(() => {
    delete window.dataLayer;
    document.body.innerHTML = '';
  });

  it('quita datos personales por nombre de campo y por contenido', () => {
    expect(
      limpiarParametros({ email: 'a@b.pe', telefono: '987654321', nombre: 'Rosa', plan: 'integral', nota: 'escríbeme a rosa@colegio.pe', celular: 'llamar al 987 654 321', alumnos: 300, ok: true, nada: null }),
    ).toEqual({ plan: 'integral', alumnos: 300, ok: true });
  });

  it('recorta los textos a 100 caracteres y une listas', () => {
    expect(limpiarParametros({ cta_text: 'x'.repeat(150), campos: ['email', 'telefono'] })).toEqual({ cta_text: 'x'.repeat(100), campos: 'email,telefono' });
  });

  it('registra el evento en el dataLayer con los parámetros limpios', () => {
    registrarEvento('form_submit', { source: 'popup', email: 'a@b.pe' });
    expect(window.dataLayer).toEqual([{ event: 'form_submit', source: 'popup' }]);
  });

  it('reconoce la sección de un clic por su id o por el título que la nombra', () => {
    document.body.innerHTML = '<header><a id="a">x</a></header><section aria-labelledby="titulo-planes"><a id="b">y</a></section><section id="videos"><a id="c">z</a></section><div><a id="d">w</a></div>';
    expect(['a', 'b', 'c', 'd'].map((id) => ubicacionDe(document.getElementById(id)))).toEqual(['cabecera', 'planes', 'videos', 'pagina']);
  });

  it('distingue WhatsApp, teléfono y correo', () => {
    expect(canalContacto('https://wa.me/51987654321')).toBe('whatsapp');
    expect(canalContacto('tel:+51987654321')).toBe('telefono');
    expect(canalContacto('mailto:hola@edunova.pe')).toBe('correo');
    expect(canalContacto('/#demo')).toBeNull();
  });

  it('cada umbral de scroll se registra una sola vez', () => {
    expect(umbralesNuevos(60, new Set())).toEqual([25, 50]);
    expect(umbralesNuevos(95, new Set([25, 50]))).toEqual([75, 90]);
    expect(umbralesNuevos(95, new Set([25, 50, 75, 90]))).toEqual([]);
  });
});
