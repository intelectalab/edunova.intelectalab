// Fechas que llegan de la API. PostgreSQL las entrega como «2026-11-19 14:43:00+00», que el motor de
// JavaScript del teléfono (Hermes) no entiende y mostraba «Invalid Date»: se pasan a ISO 8601.

export function leerFecha(texto: string): Date {
  const t = texto.trim();
  const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2})?)(\.\d+)?(Z|[+-]\d{2}(?::?\d{2})?)?$/.exec(t);
  if (!m) return new Date(t);
  const [, fecha, hora, fraccion, zona] = m;
  const ms = fraccion ? fraccion.slice(0, 4).padEnd(4, '0') : '';
  const z = !zona ? '' : zona === 'Z' ? 'Z' : zona.length === 3 ? `${zona}:00` : zona.includes(':') ? zona : `${zona.slice(0, 3)}:${zona.slice(3)}`;
  return new Date(`${fecha}T${hora}${ms}${z}`);
}
