/** Encabezado común de las secciones de la landing: insignia, H2 y descripción. */
export function Encabezado({ id, insignia, titulo, descripcion, centrado = false }: { id: string; insignia?: string | null; titulo: string; descripcion?: string | null; centrado?: boolean }) {
  return (
    <div className={`flex flex-col gap-space-sm mb-space-xl ${centrado ? 'items-center text-center' : ''}`}>
      {insignia && (
        <span className={`${centrado ? 'self-center' : 'self-start'} px-space-md py-1 rounded-full bg-secondary-container text-on-secondary-container font-label-sm text-label-sm uppercase tracking-wider`}>
          {insignia}
        </span>
      )}
      <h2 id={id} className="font-headline-lg-mobile text-headline-lg-mobile md:font-headline-lg md:text-headline-lg text-primary">
        {titulo}
      </h2>
      {descripcion && <p className={`font-body-md text-body-md text-secondary ${centrado ? 'max-w-2xl' : 'max-w-3xl'}`}>{descripcion}</p>}
    </div>
  );
}
