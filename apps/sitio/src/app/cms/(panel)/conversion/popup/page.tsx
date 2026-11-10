import { cms } from '@/contenido/provisional';
import { leerSeccion } from '../../../editor-acciones';
import { EditorPopup, type PopupCms } from './EditorPopup';

// EDUN-391 / SIT-025 · Configurador del popup de salida.
export const dynamic = 'force-dynamic';

export default async function Pagina() {
  const datos = await leerSeccion<PopupCms>('_popup');
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.popup.titulo}</h1>
      {datos ? (
        <EditorPopup inicial={datos.borrador ?? datos.publicado} medios={datos.medios} conBorrador={datos.borrador !== null} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {cms.hero.errorCarga}
        </p>
      )}
    </div>
  );
}
