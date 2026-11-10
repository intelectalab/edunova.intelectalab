// EDUN-209 · Cabeceras de seguridad del sitio público y del Panel CMS.
// CSP: solo recursos propios, Google Fonts, imágenes del almacenamiento del sitio y los
// reproductores de video permitidos (YouTube sin cookies y Vimeo). Nadie puede incrustar el
// sitio en un marco (clickjacking). EDUN-306: Google Tag Manager y GA4, que solo se cargan si el
// visitante acepta analítica o marketing en el banner de cookies.

const produccion = process.env.NODE_ENV === 'production';

/** Origen de las imágenes de la Biblioteca de medios (bucket público del sitio). */
function origenMedios() {
  try {
    return new URL(process.env.EDUNOVA_MEDIOS_URL ?? 'http://localhost:9000').origin;
  } catch {
    return '';
  }
}

// Orígenes de GTM y GA4 (guía de CSP de Google Tag Manager).
const GOOGLE = {
  // EDUN-294 · reCAPTCHA v3 del Libro de Reclamaciones (solo si hay clave del sitio).
  script: ['https://www.googletagmanager.com', 'https://www.google.com/recaptcha/', 'https://www.gstatic.com/recaptcha/'],
  frame: ['https://www.google.com/recaptcha/', 'https://recaptcha.google.com/recaptcha/'],
  img: ['https://www.googletagmanager.com', 'https://*.google-analytics.com'],
  connect: ['https://www.google.com/recaptcha/', 'https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com'],
};

/**
 * @param {{ marcoPropio?: boolean }} [o] marcoPropio: la página puede mostrarse en un marco del
 *   propio sitio (solo la vista previa del Panel CMS, EDUN-322).
 */
export function politicaContenido({ marcoPropio = false } = {}) {
  const directivas = {
    'default-src': ["'self'"],
    // Next.js inserta scripts en línea para hidratar; en desarrollo además usa eval.
    'script-src': ["'self'", "'unsafe-inline'", ...GOOGLE.script, ...(produccion ? [] : ["'unsafe-eval'"])],
    'style-src': ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
    'font-src': ["'self'", 'https://fonts.gstatic.com'],
    'img-src': ["'self'", 'data:', 'blob:', origenMedios(), ...GOOGLE.img].filter(Boolean),
    'media-src': ["'self'", origenMedios()].filter(Boolean),
    'frame-src': ["'self'", 'https://www.youtube-nocookie.com', 'https://www.youtube.com', 'https://player.vimeo.com', ...GOOGLE.frame],
    'connect-src': ["'self'", ...GOOGLE.connect, ...(produccion ? [] : ['ws:'])],
    'frame-ancestors': [marcoPropio ? "'self'" : "'none'"],
    'base-uri': ["'self'"],
    'form-action': ["'self'"],
    'object-src': ["'none'"],
  };
  return Object.entries(directivas)
    .map(([k, v]) => `${k} ${v.join(' ')}`)
    .join('; ');
}

/** @param {{ marcoPropio?: boolean }} [o] */
export function cabecerasSeguridad({ marcoPropio = false } = {}) {
  return [
    { key: 'Content-Security-Policy', value: politicaContenido({ marcoPropio }) },
    { key: 'X-Frame-Options', value: marcoPropio ? 'SAMEORIGIN' : 'DENY' },
    { key: 'X-Content-Type-Options', value: 'nosniff' },
    { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
    { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=(), payment=()' },
    ...(produccion ? [{ key: 'Strict-Transport-Security', value: 'max-age=31536000; includeSubDomains' }] : []),
  ];
}
