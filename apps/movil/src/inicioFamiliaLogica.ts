// S36 · Inicio de la familia: tipos del resumen (GET /app/familia) y formatos. Sin React Native.

export type HijoResumen = {
  id: number;
  nombres: string;
  apellidos: string;
  seccion: string | null;
  anio: number | null;
  matricula: string | null;
  tiene_foto: boolean;
  pension: { deuda_vencida: number; proximo: { id: number; descripcion: string; vencimiento: string; saldo: number; vencido: boolean } | null } | null;
  asistencia: { hoy: { estado: string; hora: string | null; origen: string } | null; porcentaje_mes: number | null } | null;
  notas_recientes: { id: number; area: string; actividad: string; valor: string; fecha: string | null; docente: string }[];
  libreta: { id: number; periodo: string } | null;
  tareas_pendientes: number;
  comunicado: ComunicadoResumen | null;
  comunicados_sin_leer: number;
};

export type ComunicadoResumen = {
  id: number;
  titulo: string;
  contenido: string;
  enviado_at: string;
  requiere_confirmacion: boolean;
  confirmado_at: string | null;
  leido_at: string | null;
};

export type ResumenFamilia = { hijos: HijoResumen[]; comunicados_sin_leer: number; modulos: string[] };

export type Tono = 'bien' | 'atencion' | 'mal' | 'neutro';

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

export const soles = (n: number) => `S/ ${n.toFixed(2)}`;

/** Fecha local del teléfono en formato AAAA-MM-DD. */
export function hoyIso(ahora = new Date()): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${ahora.getFullYear()}-${p(ahora.getMonth() + 1)}-${p(ahora.getDate())}`;
}

function partes(iso: string): { anio: number; mes: number; dia: number } {
  const [anio, mes, dia] = iso.slice(0, 10).split('-').map(Number);
  return { anio: anio!, mes: mes!, dia: dia! };
}

/** «30 de noviembre» (y el año si no es el actual). */
export function fechaLarga(iso: string, hoy = hoyIso()): string {
  const f = partes(iso);
  const texto = `${f.dia} de ${MESES[f.mes - 1]}`;
  return f.anio === partes(hoy).anio ? texto : `${texto} de ${f.anio}`;
}

/** «Jueves, 26 de noviembre». */
export function fechaDeHoy(ahora = new Date()): string {
  const d = DIAS[ahora.getDay()]!;
  return `${d[0]!.toUpperCase()}${d.slice(1)}, ${ahora.getDate()} de ${MESES[ahora.getMonth()]}`;
}

export function nombreMes(mes: string): string {
  const [anio, m] = mes.split('-').map(Number);
  const n = MESES[m! - 1]!;
  return `${n[0]!.toUpperCase()}${n.slice(1)} ${anio}`;
}

/** Mes anterior o siguiente (AAAA-MM). */
export function moverMes(mes: string, delta: number): string {
  const [anio, m] = mes.split('-').map(Number);
  const d = new Date(anio!, m! - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function diasEntre(desde: string, hasta: string): number {
  const a = partes(desde);
  const b = partes(hasta);
  return Math.round((Date.UTC(b.anio, b.mes - 1, b.dia) - Date.UTC(a.anio, a.mes - 1, a.dia)) / 86400000);
}

/** Etiqueta de la pensión: vencida, vence pronto (7 días o menos) o al día. */
export function estadoPension(p: HijoResumen['pension'], hoy = hoyIso()): { texto: string; tono: Tono } {
  if (!p?.proximo) return { texto: 'Sin pagos pendientes', tono: 'bien' };
  if (p.proximo.vencido || p.deuda_vencida > 0) return { texto: 'Vencido', tono: 'mal' };
  const faltan = diasEntre(hoy, p.proximo.vencimiento);
  if (faltan <= 7) return { texto: faltan <= 0 ? 'Vence hoy' : `Vence en ${faltan} ${faltan === 1 ? 'día' : 'días'}`, tono: 'atencion' };
  return { texto: 'Al día', tono: 'bien' };
}

/** Asistencia de hoy en palabras. Sin registro: aún no marca (o no hay clases). */
export function estadoAsistencia(hoy: { estado: string; hora: string | null } | null): { texto: string; detalle: string; tono: Tono } {
  if (!hoy) return { texto: 'Sin registro', detalle: 'Aún no marca su ingreso hoy.', tono: 'neutro' };
  const hora = hoy.hora ? `${hoy.hora} h` : '';
  switch (hoy.estado) {
    case 'P':
      return { texto: 'Presente', detalle: hora ? `Ingresó a las ${hora}, a tiempo.` : 'Asistió hoy.', tono: 'bien' };
    case 'T':
      return { texto: 'Tardanza', detalle: hora ? `Ingresó a las ${hora}.` : 'Llegó tarde hoy.', tono: 'atencion' };
    case 'J':
      return { texto: 'Justificada', detalle: 'La falta de hoy está justificada.', tono: 'neutro' };
    default:
      return { texto: 'Falta', detalle: 'No asistió hoy. Puedes justificarla en Académico.', tono: 'mal' };
  }
}

/** Color de una nota: AD/A o 14+ bien, B o 11–13 atención, C o menos de 11 mal. */
export function tonoNota(valor: string): Tono {
  const v = valor.trim().toUpperCase();
  if (v === 'AD' || v === 'A') return 'bien';
  if (v === 'B') return 'atencion';
  if (v === 'C') return 'mal';
  const n = Number(v);
  if (!Number.isFinite(n)) return 'neutro';
  return n >= 14 ? 'bien' : n >= 11 ? 'atencion' : 'mal';
}

export function nombreCorto(h: Pick<HijoResumen, 'nombres' | 'apellidos'>): string {
  return `${h.nombres.split(/\s+/)[0]} ${h.apellidos.split(/\s+/)[0] ?? ''}`.trim();
}

/** El hijo guardado si sigue en la lista; si no, el primero. */
export function hijoElegido<T extends { id: number }>(hijos: T[], guardado: number | null): T | null {
  return hijos.find((h) => h.id === guardado) ?? hijos[0] ?? null;
}

/** Semanas del mes (lunes a domingo) con el estado de cada día; null fuera del mes. */
export function calendarioMes(mes: string, dias: { fecha: string; estado: string }[]): ({ dia: number; estado: string | null } | null)[][] {
  const [anio, m] = mes.split('-').map(Number);
  const primero = new Date(anio!, m! - 1, 1);
  const total = new Date(anio!, m!, 0).getDate();
  const desfase = (primero.getDay() + 6) % 7; // lunes = 0
  const estados = new Map(dias.map((d) => [partes(d.fecha).dia, d.estado]));
  const celdas: ({ dia: number; estado: string | null } | null)[] = Array.from({ length: desfase }, () => null);
  for (let d = 1; d <= total; d++) celdas.push({ dia: d, estado: estados.get(d) ?? null });
  while (celdas.length % 7) celdas.push(null);
  const semanas = [];
  for (let i = 0; i < celdas.length; i += 7) semanas.push(celdas.slice(i, i + 7));
  return semanas;
}

export type Horario = {
  titulo: string;
  dias: Record<string, string>;
  bloques: { id: number; orden: number; hora_inicio: string; hora_fin: string; es_recreo: boolean }[];
  celdas: { dia_semana: number; bloque_horario_id: number; area: string; docente: string; ambiente: string | null }[];
};

/** Clases de un día (1 = lunes) en orden, con los recreos. */
export function clasesDelDia(h: Horario, dia: number): { inicio: string; fin: string; area: string | null; docente: string | null; ambiente: string | null; recreo: boolean }[] {
  return [...h.bloques]
    .sort((a, b) => a.orden - b.orden)
    .map((b) => {
      const c = h.celdas.find((x) => x.dia_semana === dia && x.bloque_horario_id === b.id);
      return { inicio: b.hora_inicio, fin: b.hora_fin, area: c?.area ?? null, docente: c?.docente ?? null, ambiente: c?.ambiente ?? null, recreo: b.es_recreo };
    })
    .filter((x) => x.recreo || x.area);
}

/** Día del horario que se muestra primero: hoy (lunes a sábado) o el lunes si es domingo. */
export function diaInicialHorario(ahora = new Date()): number {
  const d = ahora.getDay();
  return d === 0 ? 1 : d;
}
