import { cms } from '@/contenido/provisional';
import { leerSeccion } from '../../../editor-acciones';
import { EditorHero, type ContenidoHero } from './EditorHero';

// EDUN-233 / EDUN-322 · Hero y Portada: textos del hero y de la barra superior, con
// previsualizador en vivo. Se publica con la franja «Guardar borrador / Publicar».
export const dynamic = 'force-dynamic';

export default async function HeroYPortada() {
  const [hero, barra] = await Promise.all([leerSeccion<ContenidoHero>('hero'), leerSeccion<{ aviso: string }>('barra_superior')]);

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.hero.titulo}</h1>
      {hero && barra ? (
        <EditorHero hero={hero.borrador ?? hero.publicado} barra={barra.borrador ?? barra.publicado} conBorrador={hero.borrador !== null || barra.borrador !== null} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {cms.hero.errorCarga}
        </p>
      )}
    </div>
  );
}
