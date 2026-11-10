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

/** EDUN-403 · IDs de tracking que entrega la API (ya validados y activos). */
export type Tracking = { gtm: string | null; ga4?: string | null; meta_pixel?: string | null; linkedin?: string | null; clarity?: string | null; search_console?: string | null };

const FORMATOS: Record<keyof Omit<Tracking, 'search_console'>, RegExp> = {
  gtm: /^GTM-[A-Z0-9]{4,10}$/,
  ga4: /^G-[A-Z0-9]{6,12}$/,
  meta_pixel: /^\d{12,20}$/,
  linkedin: /^\d{5,12}$/,
  clarity: /^[a-z0-9]{8,14}$/,
};

/** Solo los IDs con formato válido (se vuelve a validar: el script va en línea en el HTML). */
export function idsValidos(t: Tracking | string | null): Record<keyof typeof FORMATOS, string> {
  const datos: Tracking = typeof t === 'string' || t === null ? { gtm: t } : t;
  return Object.fromEntries(Object.entries(FORMATOS).map(([k, re]) => [k, typeof datos[k as keyof Tracking] === 'string' && re.test(datos[k as keyof Tracking] as string) ? datos[k as keyof Tracking] : ''])) as Record<keyof typeof FORMATOS, string>;
}

/**
 * Script en línea del <head>: va antes que cualquier etiqueta. Fija el consentimiento por
 * defecto (todo denegado salvo seguridad), aplica la elección guardada y deja
 * window.edunovaConsentir para el banner. Cada etiqueta se carga solo con su categoría:
 * GTM con analítica o marketing; GA4 y Clarity con analítica; Meta Pixel y LinkedIn con marketing.
 */
export function scriptConsentimiento(tracking: Tracking | string | null): string {
  const ids = idsValidos(tracking);
  const negado = JSON.stringify(senalesConsentMode([]));
  return `(function(){
window.dataLayer=window.dataLayer||[];
function gtag(){window.dataLayer.push(arguments);}
window.gtag=gtag;
gtag('consent','default',${negado});
var I=${JSON.stringify(ids)},hecho={};
function cargar(k,src,antes){if(hecho[k])return;hecho[k]=true;if(antes)antes();var s=document.createElement('script');s.async=true;s.src=src;document.head.appendChild(s);}
function senales(c){var s=function(k){return c.indexOf(k)>=0?'granted':'denied';};return{analytics_storage:s('analitica'),ad_storage:s('marketing'),ad_user_data:s('marketing'),ad_personalization:s('marketing'),functionality_storage:s('personalizacion'),personalization_storage:s('personalizacion'),security_storage:'granted'};}
window.edunovaConsentir=function(c){
c=c||[];gtag('consent','update',senales(c));
var an=c.indexOf('analitica')>=0,mk=c.indexOf('marketing')>=0;
if(I.gtm&&(an||mk))cargar('gtm','https://www.googletagmanager.com/gtm.js?id='+I.gtm,function(){window.dataLayer.push({'gtm.start':Date.now(),event:'gtm.js'});});
if(I.ga4&&an)cargar('ga4','https://www.googletagmanager.com/gtag/js?id='+I.ga4,function(){gtag('js',new Date());gtag('config',I.ga4);});
if(I.clarity&&an)cargar('clarity','https://www.clarity.ms/tag/'+I.clarity,function(){window.clarity=window.clarity||function(){(window.clarity.q=window.clarity.q||[]).push(arguments);};});
if(I.meta_pixel&&mk)cargar('meta','https://connect.facebook.net/en_US/fbevents.js',function(){var f=window.fbq=function(){f.callMethod?f.callMethod.apply(f,arguments):f.queue.push(arguments);};f.push=f;f.loaded=true;f.version='2.0';f.queue=[];f('init',I.meta_pixel);f('track','PageView');});
if(I.linkedin&&mk)cargar('linkedin','https://snap.licdn.com/li.lms-analytics/insight.min.js',function(){window._linkedin_partner_id=I.linkedin;window._linkedin_data_partner_ids=[I.linkedin];});
};
try{var p=JSON.parse(localStorage.getItem(${JSON.stringify(CLAVE_COOKIES)})||'null');if(p&&p.categorias)window.edunovaConsentir(p.categorias);}catch(e){}
})();`;
}

// EDUN-318 · Los eventos de analítica viven en lib/analitica (con limpieza de datos personales).
export { registrarEvento } from './analitica';
