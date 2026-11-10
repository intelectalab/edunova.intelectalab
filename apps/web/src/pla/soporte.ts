// Tipos y nombres de la mesa de ayuda (consola y colegio).

export type Semaforo = { meta: string | null; color: string; porcentaje: number | null; vence: string | null };
export type Ticket = {
  id: number;
  numero: string;
  asunto: string;
  categoria: string;
  prioridad: string;
  estado: string;
  colegio: string | null;
  asignado: string | null;
  asignado_a: number | null;
  creado: string;
  vence_primera_respuesta_at: string | null;
  primera_respuesta_at: string | null;
  vence_resolucion_at: string | null;
  resuelto_at: string | null;
  calificacion: number | null;
  escalado: boolean;
  semaforo: Semaforo;
};
export type Detalle = Ticket & {
  descripcion: string;
  mensajes: { id: number; mensaje: string; interno: boolean; created_at: string; autor: string; es_staff: boolean }[];
  adjuntos: { id: number; nombre_original: string; bytes: number }[];
};
export type Fila = { tickets: number; primera_respuesta_a_tiempo: number | null; resolucion_a_tiempo: number | null; horas_primera_respuesta: number | null; horas_resolucion: number | null; calificacion: number | null };
export type Cumplimiento = { total: Fila; por_prioridad: (Fila & { prioridad: string })[]; por_colegio: (Fila & { colegio: string })[]; por_mes: (Fila & { mes: string })[] };

export const ESTADOS_TICKET: Record<string, string> = { abierto: 'Abierto', en_curso: 'En curso', esperando: 'Esperando al colegio', resuelto: 'Resuelto', cerrado: 'Cerrado' };
export const PRIORIDADES: Record<string, string> = { critica: 'Crítica', alta: 'Alta', media: 'Media', baja: 'Baja' };
