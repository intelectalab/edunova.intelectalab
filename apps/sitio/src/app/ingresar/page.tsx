import type { Metadata } from 'next';
import Link from 'next/link';
import { Icono } from '@/componentes/base';
import { accesos, sitio } from '@/contenido/provisional';

// Página de acceso: cada persona elige por dónde entrar (colegio, familia o equipo de Edunova),
// con la ayuda a la mano si no recuerda su contraseña.
export const metadata: Metadata = { title: accesos.titulo, robots: { index: false } };

const URL_APP = process.env.EDUNOVA_WEB_URL ?? 'http://localhost:5174';

export default function Ingresar() {
  const opciones = [
    { ...accesos.colegio, href: URL_APP, icono: 'school' },
    { ...accesos.familia, href: URL_APP, icono: 'family_restroom' },
    { ...accesos.equipo, href: '/cms/ingresar', icono: 'admin_panel_settings' },
  ];
  return (
    <main className="min-h-screen bg-surface-container-low flex items-center justify-center px-margin-mobile py-space-2xl">
      <div className="w-full max-w-3xl flex flex-col gap-space-lg">
        <Link href="/" className="self-start flex items-center gap-space-sm">
          <span aria-hidden="true" className="w-10 h-10 rounded-lg bg-primary text-on-primary flex items-center justify-center font-headline-md text-headline-md font-bold">{sitio.marca.charAt(0)}</span>
          <span className="font-title-lg text-title-lg text-primary">{sitio.marca}</span>
        </Link>
        <div className="flex flex-col gap-space-xs">
          <h1 className="font-headline-md text-headline-md md:font-headline-lg md:text-headline-lg text-primary">{accesos.titulo}</h1>
          <p className="font-body-lg text-body-lg text-on-surface-variant">{accesos.descripcion}</p>
        </div>
        <ul className="grid gap-space-md md:grid-cols-3">
          {opciones.map((o) => (
            <li key={o.titulo}>
              <a href={o.href} className="h-full p-space-lg rounded-xl bg-surface-container-lowest border border-surface-container hover:border-primary hover:shadow-md transition flex flex-col gap-space-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                <span className="w-11 h-11 rounded-lg bg-primary/10 text-primary flex items-center justify-center"><Icono nombre={o.icono} /></span>
                <span className="font-title-lg text-title-lg text-primary">{o.titulo}</span>
                <span className="font-body-md text-body-md text-on-surface-variant">{o.descripcion}</span>
                <span className="mt-auto inline-flex items-center gap-1 font-label-lg text-label-lg text-primary">{o.boton}<Icono nombre="arrow_forward" tamano="sm" /></span>
              </a>
            </li>
          ))}
        </ul>
        <section aria-labelledby="ayuda-acceso" className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-xs">
          <h2 id="ayuda-acceso" className="font-title-md text-title-md text-primary">{accesos.ayudaTitulo}</h2>
          <ul className="list-disc pl-6 font-body-md text-body-md text-on-surface-variant flex flex-col gap-1">
            {accesos.ayuda.map((a) => <li key={a}>{a}</li>)}
          </ul>
        </section>
      </div>
    </main>
  );
}
