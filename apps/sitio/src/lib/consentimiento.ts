// EDUN-306 / SIT-055 · Preferencias de cookies y Google Consent Mode v2.
//
// Todo empieza denegado (consent default) antes de cualquier etiqueta. GTM solo se carga si el
// visitante acepta analítica o marketing: con «Solo esenciales» no se pide nada a Google, así que
// GA4 no registra eventos. La elección queda en localStorage y una copia en la API (auditoría).

export const CLAVE_COOKIES = 'edunova_cookies';
export const EVENTO_PREFERENCIAS = 'edunova:preferencias-cookies';

export const CATEGORIAS = ['esenciales', 'analitica', 'marketing', 'personalizacion'] as const;
export type Categoria = (typeof CATEGORIAS)[number];

export type Preferencias = { categorias: Categoria[]; fecha: string };

export function normalizar(categorias: readonly string[]): Categoria[] {
  return CATEGORIAS.filter((c) => c === 'esenciales' || categorias.includes(c));
}

export function leerPreferencias(): Preferencias | null {
  try {
    const p = JSON.parse(localStorage.getItem(CLAVE_COOKIES) ?? 'null');
    return p && Array.isArray(p.categorias) ? { categorias: normalizar(p.categorias), fecha: String(p.fecha ?? '') } : null;
  } catch {
    return null;
  }
}

export function guardarPreferencias(categorias: readonly string[]): Preferencias {
  const p = { categorias: normalizar(categorias), fecha: new Date().toISOString() };
  try {
    localStorage.setItem(CLAVE_COOKIES, JSON.stringify(p));
  } catch {
    // Sin almacenamiento (modo privado estricto): la elección vale solo para esta visita.
  }
  return p;
}

/** Señales de Consent Mode v2 para unas categorías. */
export function senalesConsentMode(categorias: readonly string[]): Record<string, 'granted' | 'denied'> {
  const si = (c: Categoria) => (categorias.includes(c) ? 'granted' : 'denied');
  return {
    analytics_storage: si('analitica'),
    ad_storage: si('marketing'),
    ad_user_data: si('marketing'),
    ad_personalization: si('marketing'),
    functionality_storage: si('personalizacion'),
    personalization_storage: si('personalizacion'),
    security_storage: 'granted',
  };
}

declare global {
  interface Window {
    dataLayer?: unknown[];
    /** Definida por el script de consentimiento del <head>. */
    edunovaConsentir?: (categorias: readonly string[]) => void;
  }
}

/** Aplica una elección en esta página (Consent Mode y, si corresponde, carga GTM). */
export function aplicarPreferencias(categorias: readonly string[]): void {
  window.edunovaConsentir?.(categorias);
}

/**
 * Script en línea del <head>: va antes que cualquier etiqueta. Fija el consentimiento por
 * defecto (todo denegado salvo seguridad), aplica la elección guardada y deja
 * window.edunovaConsentir para el banner. El ID de GTM ya viene validado por la API.
 */
export function scriptConsentimiento(gtm: string | null): string {
  const id = gtm && /^GTM-[A-Z0-9]{4,10}$/.test(gtm) ? gtm : '';
  const negado = JSON.stringify(senalesConsentMode([]));
  return `(function(){
window.dataLayer=window.dataLayer||[];
function gtag(){window.dataLayer.push(arguments);}
window.gtag=gtag;
gtag('consent','default',${negado});
var GTM=${JSON.stringify(id)},cargado=false;
function senales(c){var s=function(k){return c.indexOf(k)>=0?'granted':'denied';};return{analytics_storage:s('analitica'),ad_storage:s('marketing'),ad_user_data:s('marketing'),ad_personalization:s('marketing'),functionality_storage:s('personalizacion'),personalization_storage:s('personalizacion'),security_storage:'granted'};}
window.edunovaConsentir=function(c){
c=c||[];gtag('consent','update',senales(c));
if(GTM&&!cargado&&(c.indexOf('analitica')>=0||c.indexOf('marketing')>=0)){
cargado=true;window.dataLayer.push({'gtm.start':Date.now(),event:'gtm.js'});
var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtm.js?id='+GTM;document.head.appendChild(s);}
};
try{var p=JSON.parse(localStorage.getItem(${JSON.stringify(CLAVE_COOKIES)})||'null');if(p&&p.categorias)window.edunovaConsentir(p.categorias);}catch(e){}
})();`;
}

// EDUN-318 · Los eventos de analítica viven en lib/analitica (con limpieza de datos personales).
export { registrarEvento } from './analitica';
