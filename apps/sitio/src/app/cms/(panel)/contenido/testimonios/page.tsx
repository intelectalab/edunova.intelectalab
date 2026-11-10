import { cms } from '@/contenido/provisional';
import { leerSeccion } from '../../../editor-acciones';
import { EditorTestimonios, type SeccionTestimoniosCms } from './EditorTestimonios';

// EDUN-334 / SIT-024 · Casos de Éxito: testimonios con consentimiento verificado.
export const dynamic = 'force-dynamic';

export default async function CasosDeExito() {
  const datos = await leerSeccion<SeccionTestimoniosCms>('testimonios');
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.testimonios.titulo}</h1>
      {datos ? (
        <EditorTestimonios inicial={datos.borrador ?? datos.publicado} medios={datos.medios} conBorrador={datos.borrador !== null} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {cms.hero.errorCarga}
        </p>
      )}
    </div>
  );
}
