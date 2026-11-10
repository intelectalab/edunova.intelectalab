import { describe, expect, it } from 'vitest';
import { normalizar, filtrarPreguntas } from '@/app/preguntas-frecuentes/BuscadorFaq';
import { ahoraLima, enHorario, enlaceWhatsapp } from './chatbot';
import { idsValidos, scriptConsentimiento } from './consentimiento';

// EDUN-375 · Horario y WhatsApp del chatbot.
describe('chatbot', () => {
  const horario = { dias: [1, 2, 3, 4, 5], desde: '08:00', hasta: '18:00' };

  it('usa la hora de Lima (UTC−5)', () => {
    // Martes 10/11/2026 13:30 UTC = 08:30 en Lima.
    expect(ahoraLima(new Date(Date.UTC(2026, 10, 10, 13, 30)))).toEqual({ dia: 2, hora: '08:30' });
  });

  it('atiende de lunes a viernes de 8:00 a 18:00 y no fuera de ese rango', () => {
    expect(enHorario(horario, new Date(Date.UTC(2026, 10, 10, 13, 30)))).toBe(true); // martes 08:30
    expect(enHorario(horario, new Date(Date.UTC(2026, 10, 10, 23, 0)))).toBe(false); // martes 18:00 (cierra)
    expect(enHorario(horario, new Date(Date.UTC(2026, 10, 14, 15, 0)))).toBe(false); // sábado
  });

  it('arma el enlace de WhatsApp con el mensaje inicial', () => {
    expect(enlaceWhatsapp('+51 987 654 321', 'Hola, quiero una demo')).toBe('https://wa.me/51987654321?text=Hola%2C%20quiero%20una%20demo');
    expect(enlaceWhatsapp('51987654321')).toBe('https://wa.me/51987654321');
  });
});

// EDUN-371 · Búsqueda de preguntas frecuentes.
describe('preguntas frecuentes', () => {
  const preguntas = [
    { pregunta: '¿Exporta al SIAGIE?', respuesta: 'Sí, genera los archivos.', categoria: 'SIAGIE' },
    { pregunta: '¿Cómo cobro las pensiones?', respuesta: 'Con boletas electrónicas.', categoria: 'Pagos' },
    { pregunta: '¿Hay migración?', respuesta: 'Sí, sin costo.', categoria: null },
  ];

  it('busca sin tildes ni mayúsculas y por todas las palabras', () => {
    expect(normalizar('Pensión ÁÉ')).toBe('pension ae');
    expect(filtrarPreguntas(preguntas, 'pension boletas', '').map((p) => p.categoria)).toEqual(['Pagos']);
    expect(filtrarPreguntas(preguntas, 'siagie', '')).toHaveLength(1);
    expect(filtrarPreguntas(preguntas, 'nada que ver', '')).toHaveLength(0);
  });

  it('filtra por categoría (sin categoría = General)', () => {
    expect(filtrarPreguntas(preguntas, '', 'General').map((p) => p.pregunta)).toEqual(['¿Hay migración?']);
  });
});

// EDUN-403 · Etiquetas de tracking con consentimiento.
describe('tracking', () => {
  it('descarta los IDs con formato inválido', () => {
    expect(idsValidos({ gtm: 'GTM-ABC1234', ga4: 'UA-123', meta_pixel: '123456789012345', linkedin: 'x', clarity: 'abcd1234' })).toEqual({
      gtm: 'GTM-ABC1234',
      ga4: '',
      meta_pixel: '123456789012345',
      linkedin: '',
      clarity: 'abcd1234',
    });
  });

  it('GA4 solo se carga con analítica y el píxel de Meta solo con marketing', () => {
    localStorage.clear();
    document.head.innerHTML = '';
    delete window.dataLayer;
    new Function(scriptConsentimiento({ gtm: null, ga4: 'G-ABC123XYZ', meta_pixel: '123456789012345' }))();
    const fuentes = () => [...document.head.querySelectorAll('script')].map((s) => s.src);
    window.edunovaConsentir?.(['esenciales']);
    expect(fuentes()).toEqual([]);
    window.edunovaConsentir?.(['esenciales', 'analitica']);
    expect(fuentes().some((s) => s.includes('gtag/js?id=G-ABC123XYZ'))).toBe(true);
    expect(fuentes().some((s) => s.includes('fbevents'))).toBe(false);
    window.edunovaConsentir?.(['esenciales', 'analitica', 'marketing']);
    expect(fuentes().some((s) => s.includes('fbevents'))).toBe(true);
  });
});
