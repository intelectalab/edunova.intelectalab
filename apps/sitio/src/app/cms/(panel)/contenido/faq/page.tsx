import { cms } from '@/contenido/provisional';
import { leerSeccion } from '../../../editor-acciones';
import { EditorFaq, type FaqCms } from './EditorFaq';

// EDUN-371 / SIT-007 · Preguntas frecuentes (cms.faq).
export const dynamic = 'force-dynamic';

export default async function Pagina() {
  const datos = await leerSeccion<FaqCms>('_faq');
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.faqCms.titulo}</h1>
      {datos ? (
        <EditorFaq inicial={datos.borrador ?? datos.publicado} conBorrador={datos.borrador !== null} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {cms.hero.errorCarga}
        </p>
      )}
    </div>
  );
}
