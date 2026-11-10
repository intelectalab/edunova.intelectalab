import { cms } from '@/contenido/provisional';
import { leerSeccion } from '../../../editor-acciones';
import { EditorPlanes, type SeccionPlanesCms } from './EditorPlanes';

// EDUN-326 / SIT-022 · Planes y Tarifas (prototipo #cms-planes).
export const dynamic = 'force-dynamic';

export default async function PlanesYTarifas() {
  const datos = await leerSeccion<SeccionPlanesCms>('planes');
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.planes.titulo}</h1>
      {datos ? (
        <EditorPlanes inicial={datos.borrador ?? datos.publicado} conBorrador={datos.borrador !== null} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {cms.hero.errorCarga}
        </p>
      )}
    </div>
  );
}
