// Tipos y utilidades del registro de notas (CAL).

export type Carga = { id: number; seccion_id: number; seccion: string; area: string; escala: string; docente: string };
export type Periodo = { id: number; numero: number; nombre: string; fecha_inicio: string; fecha_fin: string; fecha_limite_notas: string | null; estado: string };
export type Celda = { valor: string | null; conclusion: string | null; origen: string | null; sugerencia: string | null };
export type Resumen = { total: number; llenas: number; vacias: number; c_sin_conclusion: number; completo: boolean };
export type Grilla = {
  carga: { area: string; seccion: string };
  periodo: Periodo;
  escala: 'literal' | 'vigesimal';
  valores: string[] | null;
  alumnos: { id: number; nombre: string }[];
  competencias: { id: number; numero: number; nombre: string; nombre_corto: string | null }[];
  celdas: Record<string, Celda>;
  cerrado: boolean;
  estado_cierre: string;
  resumen: Resumen;
};
export type Actividades = {
  escala: string;
  alumnos: { id: number; nombre: string }[];
  competencias: { id: number; numero: number; nombre: string; nombre_corto: string | null }[];
  actividades: { id: number; nombre: string; fecha: string | null; competencia_id: number; notas: Record<string, { valor: string; comentario: string | null }> }[];
};

/** Nota válida según la escala del grado (vacío también es válido: se guarda como vacío). */
export function esValida(valor: string, escala: string): boolean {
  const v = valor.trim().toUpperCase();
  if (v === '') return true;
  return escala === 'literal' ? ['AD', 'A', 'B', 'C'].includes(v) : /^(0?[0-9]|1[0-9]|20)$/.test(v);
}
