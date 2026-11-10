import { cms } from '@/contenido/provisional';
import { leerSeccion } from '../../../editor-acciones';
import { EditorModulos, type SeccionModulosCms } from './EditorModulos';

// EDUN-330 / SIT-059 · Módulos Web: orden, grid principal, textos e imagen.
export const dynamic = 'force-dynamic';

export default async function ModulosWeb() {
  const datos = await leerSeccion<SeccionModulosCms>('modulos');
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.modulos.titulo}</h1>
      {datos ? (
        <EditorModulos inicial={datos.borrador ?? datos.publicado} medios={datos.medios} conBorrador={datos.borrador !== null} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {cms.hero.errorCarga}
        </p>
      )}
    </div>
  );
}
