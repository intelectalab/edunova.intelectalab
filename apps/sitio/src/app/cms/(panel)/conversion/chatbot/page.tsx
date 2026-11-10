import { cms } from '@/contenido/provisional';
import { leerSeccion } from '../../../editor-acciones';
import { EditorChatbot, type ChatbotCms } from './EditorChatbot';

// EDUN-375 / SIT-008 · Chatbot: modo, horario y flujo.
export const dynamic = 'force-dynamic';

export default async function Pagina() {
  const datos = await leerSeccion<ChatbotCms>('_chatbot');
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.chatbotCms.titulo}</h1>
      {datos ? (
        <EditorChatbot inicial={datos.borrador ?? datos.publicado} conBorrador={datos.borrador !== null} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {cms.hero.errorCarga}
        </p>
      )}
    </div>
  );
}
