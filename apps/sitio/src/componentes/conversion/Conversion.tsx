import { scriptConsentimiento, type Tracking } from '@/lib/consentimiento';
import type { Chatbot } from '@/lib/landing';
import type { ConfigPopup } from '@/lib/popupSalida';
import { Analitica } from './Analitica';
import { BannerCookies } from './BannerCookies';
import { ChatbotWidget } from './ChatbotWidget';
import { PopupSalida } from './PopupSalida';
import { SolicitarDemo } from './SolicitarDemo';
import { VentanaPolitica } from './VentanaPolitica';

/**
 * Sprint 6 · Piezas comunes de las páginas públicas: Consent Mode v2 (antes que cualquier
 * etiqueta), la solicitud de demo, la ventana de políticas (encima de la demo) y el banner.
 * Sprint 8 · Eventos de analítica (EDUN-318) y, solo en la landing, el popup de salida (EDUN-271).
 * Sprint 11 · Todas las etiquetas de tracking con consentimiento (EDUN-403) y el chatbot (EDUN-375).
 */
export function Conversion({ tracking, popup = null, chatbot = null }: { tracking: Tracking | null; popup?: ConfigPopup | null; chatbot?: Chatbot | null }) {
  return (
    <>
      <script id="consentimiento" dangerouslySetInnerHTML={{ __html: scriptConsentimiento(tracking) }} />
      <Analitica />
      <SolicitarDemo />
      {popup && <PopupSalida config={popup} />}
      {chatbot && <ChatbotWidget config={chatbot} />}
      <VentanaPolitica />
      <BannerCookies />
    </>
  );
}
