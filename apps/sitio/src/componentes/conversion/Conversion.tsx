import { scriptConsentimiento } from '@/lib/consentimiento';
import { BannerCookies } from './BannerCookies';
import { SolicitarDemo } from './SolicitarDemo';
import { VentanaPolitica } from './VentanaPolitica';

/**
 * Sprint 6 · Piezas comunes de las páginas públicas: Consent Mode v2 (antes que cualquier
 * etiqueta), la solicitud de demo, la ventana de políticas (encima de la demo) y el banner.
 */
export function Conversion({ gtm }: { gtm: string | null }) {
  return (
    <>
      <script id="consentimiento" dangerouslySetInnerHTML={{ __html: scriptConsentimiento(gtm) }} />
      <SolicitarDemo />
      <VentanaPolitica />
      <BannerCookies />
    </>
  );
}
