import { cms } from '@/contenido/provisional';
import { listarMedios } from '../../medios-acciones';
import { Biblioteca } from './Biblioteca';

// EDUN-237 · Biblioteca de medios del sitio: subir y ver los archivos con su vista previa.
export default async function PaginaMedios() {
  const medios = await listarMedios();
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.medios.titulo}</h1>
      <p className="font-body-md text-body-md text-on-surface-variant">{cms.medios.descripcion}</p>
      <Biblioteca inicial={medios} />
    </div>
  );
}
