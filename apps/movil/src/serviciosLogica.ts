// Lógica sin interfaz de los servicios en la app (EDUN-166), probada con Vitest.

/** «350 m» o «2.4 km». */
export function distanciaLegible(metros: number): string {
  return metros >= 1000 ? `${(metros / 1000).toFixed(1)} km` : `${Math.round(metros)} m`;
}
