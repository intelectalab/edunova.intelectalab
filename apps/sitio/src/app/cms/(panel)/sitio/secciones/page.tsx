import { cookies } from 'next/headers';
import { cms } from '@/contenido/provisional';
import { COOKIE_CMS, llamarApi } from '@/lib/sesionCms';
import { EditorHero, type ContenidoHero } from './EditorHero';

// EDUN-233 · Primera pantalla editable del CMS: los textos del hero. La pantalla completa de
// «Hero y Portada» (previsualizador, color del botón, resúmenes) llega con su historia.
export default async function HeroYPortada() {
  const token = (await cookies()).get(COOKIE_CMS)?.value;
  const r = await llamarApi('/cms/secciones/hero', { token }).catch(() => null);
  const datos = r?.ok ? ((await r.json()).data as { publicado: ContenidoHero; borrador: ContenidoHero | null }) : null;

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.hero.titulo}</h1>
      {datos ? (
        <EditorHero inicial={datos.borrador ?? datos.publicado} conBorrador={datos.borrador !== null} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {cms.hero.errorCarga}
        </p>
      )}
    </div>
  );
}
