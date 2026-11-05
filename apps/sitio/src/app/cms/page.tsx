import { cms } from '@/contenido/provisional';

export default function InicioCms() {
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-xs">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{cms.tituloInicio}</h1>
      <p className="font-body-md text-body-md text-on-surface-variant">{cms.descripcionInicio}</p>
    </div>
  );
}
