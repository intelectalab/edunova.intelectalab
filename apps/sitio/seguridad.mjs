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
  script: ['https://www.googletagmanager.com'],
  img: ['https://www.googletagmanager.com', 'https://*.google-analytics.com'],
  connect: ['https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com'],
};

export function politicaContenido() {
  const directivas = {
    'default-src': ["'self'"],
    // Next.js inserta scripts en línea para hidratar; en desarrollo además usa eval.
    'script-src': ["'self'", "'unsafe-inline'", ...GOOGLE.script, ...(produccion ? [] : ["'unsafe-eval'"])],
    'style-src': ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
    'font-src': ["'self'", 'https://fonts.gstatic.com'],
    'img-src': ["'self'", 'data:', 'blob:', origenMedios(), ...GOOGLE.img].filter(Boolean),
    'media-src': ["'self'", origenMedios()].filter(Boolean),
    'frame-src': ['https://www.youtube-nocookie.com', 'https://www.youtube.com', 'https://player.vimeo.com'],
    'connect-src': ["'self'", ...GOOGLE.connect, ...(produccion ? [] : ['ws:'])],
    'frame-ancestors': ["'none'"],
    'base-uri': ["'self'"],
    'form-action': ["'self'"],
    'object-src': ["'none'"],
  };
  return Object.entries(directivas)
    .map(([k, v]) => `${k} ${v.join(' ')}`)
    .join('; ');
}

export function cabecerasSeguridad() {
  return [
    { key: 'Content-Security-Policy', value: politicaContenido() },
    { key: 'X-Frame-Options', value: 'DENY' },
    { key: 'X-Content-Type-Options', value: 'nosniff' },
    { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
    { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=(), payment=()' },
    ...(produccion ? [{ key: 'Strict-Transport-Security', value: 'max-age=31536000; includeSubDomains' }] : []),
  ];
}
