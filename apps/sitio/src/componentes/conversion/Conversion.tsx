import { scriptConsentimiento } from '@/lib/consentimiento';
import type { ConfigPopup } from '@/lib/popupSalida';
import { Analitica } from './Analitica';
import { BannerCookies } from './BannerCookies';
import { PopupSalida } from './PopupSalida';
import { SolicitarDemo } from './SolicitarDemo';
import { VentanaPolitica } from './VentanaPolitica';

/**
 * Sprint 6 · Piezas comunes de las páginas públicas: Consent Mode v2 (antes que cualquier
 * etiqueta), la solicitud de demo, la ventana de políticas (encima de la demo) y el banner.
 * Sprint 8 · Eventos de analítica (EDUN-318) y, solo en la landing, el popup de salida (EDUN-271).
 */
export function Conversion({ gtm, popup = null }: { gtm: string | null; popup?: ConfigPopup | null }) {
  return (
    <>
      <script id="consentimiento" dangerouslySetInnerHTML={{ __html: scriptConsentimiento(gtm) }} />
      <Analitica />
      <SolicitarDemo />
      {popup && <PopupSalida config={popup} />}
      <VentanaPolitica />
      <BannerCookies />
    </>
  );
}
