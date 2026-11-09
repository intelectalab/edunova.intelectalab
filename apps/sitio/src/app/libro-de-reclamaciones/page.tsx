import type { Metadata } from 'next';
import Link from 'next/link';
import { Icono } from '@/componentes/base';
import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { FormularioReclamo } from '@/componentes/reclamos/FormularioReclamo';
import { reclamos as t } from '@/contenido/provisional';
import { oConfirmar } from '@/lib/formato';
import { obtenerLanding } from '@/lib/landing';

// EDUN-294 / SIT-010 · Libro de Reclamaciones virtual (Stitch S-WEB-06). Los datos de Edunova
// salen de cms.config («[A CONFIRMAR]» si faltan); nada de RUC, sellos ni plazos de ejemplo.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = {
  title: 'Libro de Reclamaciones · Edunova',
  alternates: { canonical: '/libro-de-reclamaciones' },
};

export default async function PaginaLibro() {
  const datos = await obtenerLanding();
  const c = datos?.config ?? {};
  const empresa: [string, string, string][] = [
    ['business', t.empresa.razon, oConfirmar(c.razon_social)],
    ['badge', t.empresa.ruc, oConfirmar(c.ruc)],
    ['pin_drop', t.empresa.direccion, oConfirmar(c.direccion)],
    ['mail', t.empresa.correo, oConfirmar(c.email_contacto)],
    ['call', t.empresa.telefono, oConfirmar(c.telefono_central)],
  ];

  return (
    <MarcoPublico datos={datos}>
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin flex flex-col gap-space-lg">
        <nav aria-label={t.migas} className="flex items-center gap-space-xs font-label-md text-label-md text-secondary">
          <Link href="/" className="hover:text-primary">
            {t.inicio}
          </Link>
          <span aria-hidden="true">/</span>
          <span>{t.legal}</span>
          <span aria-hidden="true">/</span>
          <span aria-current="page" className="text-primary font-semibold">
            {t.titulo}
          </span>
        </nav>

        <header className="flex flex-col gap-space-sm">
          <ul className="flex flex-wrap gap-space-xs">
            {t.sellos.map((s, i) => (
              <li key={s} className="inline-flex items-center gap-1 px-space-sm py-1 rounded-full bg-secondary-container text-on-secondary-container font-label-sm text-label-sm">
                <Icono nombre={i === 0 ? 'policy' : 'schedule'} tamano="sm" />
                {s}
              </li>
            ))}
          </ul>
          <h1 className="font-headline-lg-mobile text-headline-lg-mobile md:font-headline-lg md:text-headline-lg text-primary">{t.titulo}</h1>
          <p className="font-body-lg text-body-lg text-on-surface-variant max-w-3xl">{t.intro(oConfirmar(c.razon_social), oConfirmar(c.ruc))}</p>
          <Link href="/libro-de-reclamaciones/seguimiento" className="self-start inline-flex items-center gap-space-xs font-label-lg text-label-lg text-primary underline">
            <Icono nombre="manage_search" tamano="sm" />
            {t.seguimientoEnlace}
          </Link>
        </header>

        <div className="grid grid-cols-1 lg:grid-cols-[1fr_22rem] gap-space-lg items-start">
          <FormularioReclamo claveRecaptcha={process.env.RECAPTCHA_SITIO || null} />

          <aside className="flex flex-col gap-space-md">
            <section aria-labelledby="titulo-plazo" className="p-space-md rounded-2xl bg-primary text-on-primary flex flex-col gap-1">
              <h2 id="titulo-plazo" className="font-title-lg text-title-lg flex items-center gap-space-xs">
                <Icono nombre="schedule" />
                {t.plazo.titulo}
              </h2>
              <p className="font-body-sm text-body-sm text-primary-fixed">{t.plazo.texto}</p>
            </section>
            <section aria-labelledby="titulo-empresa" className="p-space-md rounded-2xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-sm">
              <h2 id="titulo-empresa" className="font-title-lg text-title-lg text-primary">
                {t.empresa.titulo}
              </h2>
              <dl className="flex flex-col gap-space-xs font-body-sm text-body-sm">
                {empresa.map(([icono, k, v]) => (
                  <div key={k} className="flex items-start gap-space-xs">
                    <dt className="flex items-start gap-space-xs font-semibold shrink-0">
                      <Icono nombre={icono} tamano="sm" className="text-primary mt-0.5" />
                      {k}:
                    </dt>
                    <dd className="break-words min-w-0">{v}</dd>
                  </div>
                ))}
              </dl>
            </section>
            <section aria-labelledby="titulo-ayuda" className="p-space-md rounded-2xl bg-surface-container-low flex flex-col gap-1">
              <h2 id="titulo-ayuda" className="font-title-md text-title-md text-primary flex items-center gap-space-xs">
                <Icono nombre="bolt" tamano="sm" />
                {t.atencion.titulo}
              </h2>
              <p className="font-body-sm text-body-sm text-on-surface-variant">{t.atencion.texto}</p>
            </section>
            <section aria-labelledby="titulo-indecopi" className="p-space-md rounded-2xl bg-surface-container-low flex flex-col gap-1">
              <h2 id="titulo-indecopi" className="font-title-md text-title-md text-primary flex items-center gap-space-xs">
                <Icono nombre="account_balance" tamano="sm" />
                {t.indecopi.titulo}
              </h2>
              <p className="font-body-sm text-body-sm text-on-surface-variant">{t.indecopi.texto}</p>
              <a href={t.indecopi.url} target="_blank" rel="noopener" className="self-start inline-flex items-center gap-1 font-label-lg text-label-lg text-primary underline">
                {t.indecopi.enlace}
                <Icono nombre="open_in_new" tamano="sm" />
              </a>
            </section>
          </aside>
        </div>
      </div>
    </MarcoPublico>
  );
}
