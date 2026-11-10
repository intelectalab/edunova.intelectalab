import type { LogoCliente, SeccionLogos } from '@/lib/landing';

/**
 * EDUN-338 / SIT-060 · «Confían en Edunova»: carrusel continuo con los logos autorizados.
 * Velocidad (segundos por vuelta) y columnas salen del CMS. Con «reducir movimiento» se
 * muestran quietos en una grilla. Solo se pinta si hay logos (la API no publica la sección vacía).
 */
export function Logos({ datos, logos }: { datos: SeccionLogos; logos: LogoCliente[] }) {
  if (logos.length === 0) return null;
  const columnas = Math.min(Math.max(datos.columnas, 3), 8);
  const anchoLogo = `calc((100% - ${columnas - 1} * 2rem) / ${columnas})`;
  const mover = logos.length > columnas;
  const lista = mover ? [...logos, ...logos] : logos;

  return (
    <section id="clientes" aria-labelledby="titulo-logos" className="w-full py-space-xl bg-background">
      <style>{`@keyframes edunova-logos{from{transform:translateX(0)}to{transform:translateX(-50%)}}
@media (prefers-reduced-motion: reduce){[data-carrusel-logos]{animation:none!important;flex-wrap:wrap;justify-content:center;width:100%!important}[data-logo-copia]{display:none}}`}</style>
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin flex flex-col gap-space-lg">
        <h2 id="titulo-logos" className="text-center font-title-lg text-title-lg text-secondary">
          {datos.titulo}
        </h2>
        <div className="overflow-hidden [mask-image:linear-gradient(to_right,transparent,black_8%,black_92%,transparent)]">
          <ul
            data-carrusel-logos
            className="flex items-center gap-8"
            style={mover ? { width: `${(lista.length / columnas) * 100}%`, animation: `edunova-logos ${Math.max(datos.velocidad, 10)}s linear infinite` } : { justifyContent: 'center' }}
          >
            {lista.map((l, i) => {
              const copia = i >= logos.length;
              const img = (
                // eslint-disable-next-line @next/next/no-img-element -- logos de la Biblioteca (bucket del sitio), SVG o PNG
                <img src={l.logo.url} alt={copia ? '' : l.nombre} width={l.logo.ancho ?? undefined} height={l.logo.alto ?? undefined} className="max-h-14 w-auto mx-auto object-contain grayscale hover:grayscale-0 transition" loading="lazy" />
              );
              return (
                <li key={i} data-logo-copia={copia || undefined} aria-hidden={copia || undefined} className="shrink-0 flex items-center justify-center h-16" style={{ width: mover ? undefined : '10rem', flexBasis: mover ? anchoLogo : undefined }}>
                  {l.sitio_web && !copia ? (
                    <a href={l.sitio_web} target="_blank" rel="noopener nofollow" className="block">
                      {img}
                    </a>
                  ) : (
                    img
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      </div>
    </section>
  );
}
