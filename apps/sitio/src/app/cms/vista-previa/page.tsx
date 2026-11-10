import { PortadaEnVivo } from './PortadaEnVivo';

// EDUN-322 · Página que el previsualizador del Panel CMS muestra en un marco. No lee datos:
// pinta lo que le manda el editor (mismo origen) con los componentes reales de la landing.
// Es la única ruta que admite frame-ancestors 'self' (next.config.ts).
export const dynamic = 'force-static';

export default function VistaPrevia() {
  return <PortadaEnVivo />;
}
