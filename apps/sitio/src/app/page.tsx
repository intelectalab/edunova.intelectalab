import { Cabecera } from '@/componentes/landing/Cabecera';
import { Hero } from '@/componentes/landing/Hero';
import { Modulos } from '@/componentes/landing/Modulos';
import { sitio } from '@/contenido/provisional';
import { obtenerLanding } from '@/lib/landing';

// Landing pública (EDUN-242). TRASPASO §3: SSR en la landing e ISR de 5 minutos para el
// contenido del CMS. La página se arma en cada visita; los datos del CMS quedan en caché
// 5 minutos con la etiqueta «landing», que la API revalida al publicar (EDUN-233).
export const dynamic = 'force-dynamic';

const URL_INGRESO = process.env.EDUNOVA_WEB_URL ?? 'http://localhost:5174';

export default async function Inicio() {
  const datos = await obtenerLanding();
  const hero = datos?.secciones.hero;

  if (!datos || !hero) {
    return (
      <main className="max-w-5xl mx-auto px-margin-mobile md:px-margin py-space-2xl flex flex-col gap-space-md">
        <h1 className="font-headline-lg-mobile text-headline-lg-mobile md:font-display-lg md:text-display-lg text-primary">{sitio.titulo}</h1>
        <p className="font-body-lg text-body-lg text-on-surface-variant">{sitio.enConstruccion}</p>
      </main>
    );
  }

  return (
    <>
      <Cabecera
        aviso={datos.secciones.barra_superior?.aviso ?? ''}
        telefono={datos.config.telefono_central ?? null}
        menu={datos.menu.principal}
        urlIngreso={URL_INGRESO}
      />
      <main className="w-full pt-28 bg-surface">
        <Hero datos={hero} />
        {datos.secciones.modulos && datos.modulos?.length > 0 && <Modulos seccion={datos.secciones.modulos} modulos={datos.modulos} />}
      </main>
    </>
  );
}
