import { sitio } from '@/contenido/provisional';

// Landing pública. Su contenido real llega con las historias de la landing (EP-SIT).
export default function Inicio() {
  return (
    <main className="max-w-5xl mx-auto px-margin-mobile md:px-margin py-space-2xl flex flex-col gap-space-md">
      <h1 className="font-headline-lg-mobile text-headline-lg-mobile md:font-display-lg md:text-display-lg text-primary">
        {sitio.titulo}
      </h1>
      <p className="font-body-lg text-body-lg text-on-surface-variant">{sitio.enConstruccion}</p>
    </main>
  );
}
