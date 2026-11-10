import { cms } from '@/contenido/provisional';
import { leerSeccion } from '../../../editor-acciones';
import { EditorLogos, type SeccionLogosCms } from './EditorLogos';

// EDUN-338 / SIT-060 · Logos de clientes («Confían en Edunova»).
export const dynamic = 'force-dynamic';

export default async function Pagina() {
  const datos = await leerSeccion<SeccionLogosCms>('logos');
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.logos.titulo}</h1>
      {datos ? (
        <EditorLogos inicial={datos.borrador ?? datos.publicado} medios={datos.medios} conBorrador={datos.borrador !== null} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {cms.hero.errorCarga}
        </p>
      )}
    </div>
  );
}
