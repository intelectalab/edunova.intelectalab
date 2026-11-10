import { cms } from '@/contenido/provisional';
import { leerSeccion } from '../../../editor-acciones';
import { EditorEstructura, type Estructura } from './EditorEstructura';

// EDUN-387 / SIT-020 · Editor de secciones: ordenar y ocultar las secciones de la landing.
export const dynamic = 'force-dynamic';

export default async function Pagina() {
  const datos = await leerSeccion<Estructura>('_estructura');
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.estructura.titulo}</h1>
      {datos ? (
        <EditorEstructura inicial={datos.borrador ?? datos.publicado} conBorrador={datos.borrador !== null} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {cms.hero.errorCarga}
        </p>
      )}
    </div>
  );
}
