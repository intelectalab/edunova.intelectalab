// S36 · Navegación según el rol (MOB-H06): cada grupo de roles tiene sus propias pestañas.
// Sin React Native para poder probarlo con Vitest.

export type Grupo = 'familia' | 'alumno' | 'docente' | 'operacion' | 'oficina';
export type Pestana = 'inicio' | 'pagos' | 'academico' | 'avisos' | 'perfil';

const DOCENTE = ['docente', 'tutor', 'coordinador'];
const OPERACION = ['auxiliar', 'porteria', 'conductor', 'movilidad', 'enfermeria', 'comedor', 'nutricionista'];

export const NOMBRE_GRUPO: Record<Grupo, string> = {
  familia: 'Familia',
  alumno: 'Alumno',
  docente: 'Docente',
  operacion: 'Operación',
  oficina: 'Oficina',
};

/** Grupos que puede usar quien tiene estos roles, en orden de preferencia. */
export function gruposDe(roles: string[]): Grupo[] {
  const g: Grupo[] = [];
  if (roles.includes('apoderado')) g.push('familia');
  if (roles.includes('alumno')) g.push('alumno');
  if (roles.some((r) => DOCENTE.includes(r))) g.push('docente');
  if (roles.some((r) => OPERACION.includes(r))) g.push('operacion');
  if (roles.some((r) => !['apoderado', 'alumno', ...DOCENTE, ...OPERACION].includes(r))) g.push('oficina');
  return g.length ? g : ['oficina'];
}

/** El grupo guardado en el teléfono si todavía le corresponde; si no, el primero. */
export function grupoInicial(roles: string[], guardado: string | null): Grupo {
  const grupos = gruposDe(roles);
  return grupos.find((g) => g === guardado) ?? grupos[0]!;
}

/** Pestañas del grupo. La familia tiene la app completa; los demás grupos se rehacen en S37–S39. */
export function pestanasDe(grupo: Grupo, modulos: string[] | undefined): Pestana[] {
  if (grupo !== 'familia') return ['inicio', 'perfil'];
  const conPagos = !modulos || modulos.includes('PAG') || modulos.includes('TIE');
  return conPagos ? ['inicio', 'pagos', 'academico', 'avisos', 'perfil'] : ['inicio', 'academico', 'avisos', 'perfil'];
}

const AVISO_A_PESTANA: Record<string, Pestana> = {
  pagos: 'pagos',
  convenios: 'pagos',
  tienda: 'pagos',
  notas: 'academico',
  aula: 'academico',
  agenda: 'avisos',
  comunicados: 'avisos',
  mensajes: 'avisos',
  citas: 'avisos',
  notificaciones: 'avisos',
  preferencias: 'perfil',
};

/** Pestaña que abre un aviso push según su `pantalla`; lo desconocido abre el inicio. */
export function pestanaDeAviso(pantalla: string | undefined, pestanas: Pestana[]): Pestana {
  const p = pantalla ? AVISO_A_PESTANA[pantalla] : undefined;
  return p && pestanas.includes(p) ? p : 'inicio';
}

export type SeccionAcademico = 'notas' | 'asistencia' | 'tareas' | 'horario';
export type ClaveServicio = 'movilidad' | 'comedor' | 'salud' | 'recojo' | 'autorizaciones' | 'tramites' | 'biblioteca';

/** Rutas de la app: las pestañas y la pantalla de un servicio (se abre encima). */
export type RaizParams = { pestanas: { screen?: Pestana } | undefined; servicio: { clave: ClaveServicio } };
export type PestanasParams = { inicio: undefined; pagos: undefined; academico: { seccion?: SeccionAcademico } | undefined; avisos: undefined; perfil: undefined };

const SERVICIOS: { clave: ClaveServicio; titulo: string; icono: string; modulo: string }[] = [
  { clave: 'movilidad', titulo: 'Movilidad', icono: 'bus-outline', modulo: 'MOV' },
  { clave: 'comedor', titulo: 'Menú del comedor', icono: 'restaurant-outline', modulo: 'COD' },
  { clave: 'recojo', titulo: 'Recojo y salidas', icono: 'people-outline', modulo: 'POR' },
  { clave: 'autorizaciones', titulo: 'Autorizaciones', icono: 'create-outline', modulo: 'DOC' },
  { clave: 'tramites', titulo: 'Trámites', icono: 'document-text-outline', modulo: 'DOC' },
  { clave: 'salud', titulo: 'Ficha de salud', icono: 'medkit-outline', modulo: 'SAL' },
  { clave: 'biblioteca', titulo: 'Biblioteca', icono: 'library-outline', modulo: 'BIB' },
];

/** Servicios del colegio que se muestran a la familia (solo los módulos encendidos). */
export function serviciosFamilia(modulos: string[] | undefined): { clave: ClaveServicio; titulo: string; icono: string }[] {
  return SERVICIOS.filter((s) => !modulos || modulos.includes(s.modulo)).map(({ clave, titulo, icono }) => ({ clave, titulo, icono }));
}

export function tituloServicio(clave: ClaveServicio): string {
  return SERVICIOS.find((s) => s.clave === clave)?.titulo ?? 'Servicio';
}
