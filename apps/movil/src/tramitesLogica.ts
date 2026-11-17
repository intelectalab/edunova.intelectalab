// Lógica sin interfaz de los trámites en la app (EDUN-114), probada con Vitest.

export type Requisito = { id: number; descripcion: string; obligatorio: boolean };

/** Requisitos obligatorios que faltan adjuntar: el trámite no se envía hasta tenerlos. */
export function requisitosFaltantes(tipo: { requisitos: Requisito[] } | undefined, fotos: Record<number, unknown>): string[] {
  return (tipo?.requisitos ?? []).filter((r) => r.obligatorio && !fotos[r.id]).map((r) => r.descripcion);
}
