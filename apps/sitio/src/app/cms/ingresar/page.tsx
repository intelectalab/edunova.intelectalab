import type { Metadata } from 'next';
import { cmsIngreso, sitio } from '@/contenido/provisional';
import { FormularioIngreso } from './FormularioIngreso';

export const metadata: Metadata = { title: cmsIngreso.titulo };

export default async function IngresarCms({ searchParams }: { searchParams: Promise<{ volver?: string }> }) {
  const { volver } = await searchParams;
  return (
    <main className="min-h-screen bg-surface-container-low flex items-center justify-center px-margin-mobile py-space-2xl">
      <div className="w-full max-w-md bg-surface-container-lowest rounded-xl shadow-sm p-space-xl flex flex-col gap-space-lg">
        <div className="flex items-center gap-space-sm">
          <span aria-hidden="true" className="w-10 h-10 rounded-lg bg-primary text-on-primary flex items-center justify-center font-headline-md text-headline-md font-bold">
            {sitio.marca.charAt(0)}
          </span>
          <span className="flex flex-col">
            <span className="font-title-lg text-title-lg text-primary">{sitio.marca}</span>
            <span className="font-label-sm text-label-sm text-secondary uppercase tracking-wider">{cmsIngreso.subtitulo}</span>
          </span>
        </div>
        <h1 className="font-headline-md text-headline-md text-primary">{cmsIngreso.titulo}</h1>
        <FormularioIngreso volver={volver ?? '/cms'} />
        <p className="font-body-sm text-body-sm text-on-surface-variant">
          {cmsIngreso.olvidaste}{' '}
          <a href={process.env.EDUNOVA_WEB_URL ?? 'http://localhost:5174'} className="text-primary font-semibold underline">{cmsIngreso.recuperar}</a>
        </p>
      </div>
    </main>
  );
}
