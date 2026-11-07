import { beforeEach, describe, expect, it } from 'vitest';
import { CLAVE_COOKIES, guardarPreferencias, leerPreferencias, normalizar, scriptConsentimiento, senalesConsentMode } from './consentimiento';

// EDUN-306 · Preferencias de cookies y Consent Mode v2.
describe('consentimiento de cookies', () => {
  beforeEach(() => {
    localStorage.clear();
    document.head.querySelectorAll('script[src*="googletagmanager"]').forEach((s) => s.remove());
    delete window.dataLayer;
    delete window.edunovaConsentir;
  });

  it('«esenciales» siempre va y no se aceptan categorías desconocidas', () => {
    expect(normalizar([])).toEqual(['esenciales']);
    expect(normalizar(['marketing', 'rastreo', 'analitica'])).toEqual(['esenciales', 'analitica', 'marketing']);
  });

  it('guarda y lee la elección del navegador', () => {
    expect(leerPreferencias()).toBeNull();
    guardarPreferencias(['analitica']);
    expect(leerPreferencias()?.categorias).toEqual(['esenciales', 'analitica']);
    localStorage.setItem(CLAVE_COOKIES, 'no es json');
    expect(leerPreferencias()).toBeNull();
  });

  it('traduce las categorías a las señales de Consent Mode v2', () => {
    expect(senalesConsentMode(['esenciales'])).toEqual({
      analytics_storage: 'denied',
      ad_storage: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
      functionality_storage: 'denied',
      personalization_storage: 'denied',
      security_storage: 'granted',
    });
    expect(senalesConsentMode(['esenciales', 'marketing'])).toMatchObject({ ad_storage: 'granted', ad_user_data: 'granted', analytics_storage: 'denied' });
  });

  it('el script deniega por defecto y solo carga GTM con analítica o marketing', () => {
    new Function(scriptConsentimiento('GTM-PRUEBA1'))();
    const consentimientos = () => (window.dataLayer ?? []).filter((e) => (e as IArguments)[0] === 'consent') as IArguments[];
    expect(consentimientos()[0][1]).toBe('default');
    expect(consentimientos()[0][2].analytics_storage).toBe('denied');

    window.edunovaConsentir?.(['esenciales', 'personalizacion']);
    expect(document.querySelector('script[src*="googletagmanager"]')).toBeNull();

    window.edunovaConsentir?.(['esenciales', 'analitica']);
    expect(document.querySelector('script[src*="googletagmanager"]')?.getAttribute('src')).toBe('https://www.googletagmanager.com/gtm.js?id=GTM-PRUEBA1');
    expect(consentimientos().at(-1)?.[2].analytics_storage).toBe('granted');
  });

  it('aplica la elección guardada al cargar la página', () => {
    localStorage.setItem(CLAVE_COOKIES, JSON.stringify({ categorias: ['esenciales', 'marketing'] }));
    new Function(scriptConsentimiento('GTM-PRUEBA1'))();
    expect(document.querySelectorAll('script[src*="googletagmanager"]')).toHaveLength(1);
  });

  it('sin un ID de GTM válido no carga nada', () => {
    new Function(scriptConsentimiento('GTM-XX"; alert(1); "'))();
    window.edunovaConsentir?.(['analitica']);
    expect(document.querySelector('script[src*="googletagmanager"]')).toBeNull();
  });
});
