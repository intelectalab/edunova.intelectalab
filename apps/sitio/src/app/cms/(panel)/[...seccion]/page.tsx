import { notFound } from 'next/navigation';
import { TarjetaSeccion } from '@/componentes/base';
import { cms, enlacePorRuta } from '@/contenido/provisional';

// Pantallas del menú que todavía no tienen su historia: muestran su lugar en el marco (EDUN-230).
// Cada historia del sitio reemplaza su ruta por la pantalla real.
export default async function SeccionCms({ params }: { params: Promise<{ seccion: string[] }> }) {
  const { seccion } = await params;
  const enlace = enlacePorRuta('/cms/' + seccion.join('/'));
  if (!enlace) notFound();

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{enlace.etiqueta}</h1>
      <TarjetaSeccion icono={enlace.icono} titulo={enlace.etiqueta} descripcion={enlace.descripcion}>
        <p className="font-body-md text-body-md text-on-surface-variant">{cms.enConstruccion}</p>
      </TarjetaSeccion>
    </div>
  );
}
