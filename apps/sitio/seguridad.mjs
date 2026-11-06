// EDUN-209 · Cabeceras de seguridad del sitio público y del Panel CMS.
// CSP: solo recursos propios, Google Fonts, imágenes del almacenamiento del sitio y los
// reproductores de video permitidos (YouTube sin cookies y Vimeo). Nadie puede incrustar el
// sitio en un marco (clickjacking).

const produccion = process.env.NODE_ENV === 'production';

/** Origen de las imágenes de la Biblioteca de medios (bucket público del sitio). */
function origenMedios() {
  try {
    return new URL(process.env.EDUNOVA_MEDIOS_URL ?? 'http://localhost:9000').origin;
  } catch {
    return '';
  }
}

export function politicaContenido() {
  const directivas = {
    'default-src': ["'self'"],
    // Next.js inserta scripts en línea para hidratar; en desarrollo además usa eval.
    'script-src': ["'self'", "'unsafe-inline'", ...(produccion ? [] : ["'unsafe-eval'"])],
    'style-src': ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
    'font-src': ["'self'", 'https://fonts.gstatic.com'],
    'img-src': ["'self'", 'data:', 'blob:', origenMedios()].filter(Boolean),
    'media-src': ["'self'", origenMedios()].filter(Boolean),
    'frame-src': ['https://www.youtube-nocookie.com', 'https://www.youtube.com', 'https://player.vimeo.com'],
    'connect-src': ["'self'", ...(produccion ? [] : ['ws:'])],
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
