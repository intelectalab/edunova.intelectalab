import Link from 'next/link';
import { Icono } from '@/componentes/base';
import { landing, sitio } from '@/contenido/provisional';
import { oConfirmar } from '@/lib/formato';
import type { ItemMenu, SeccionCtaFinal, SeccionFooter } from '@/lib/landing';

type Config = Record<string, string | number | boolean | null>;

/** EDUN-263 · Banner final con el botón de demo y la central telefónica. */
export function CtaFinal({ datos, telefono }: { datos: SeccionCtaFinal; telefono: unknown }) {
  return (
    <section aria-labelledby="titulo-cta" className="w-full py-space-2xl bg-primary text-on-primary">
      <div className="max-w-5xl mx-auto px-margin-mobile md:px-margin flex flex-col items-center text-center gap-space-md">
        {datos.insignia && (
          <span className="inline-flex items-center gap-space-xs px-space-md py-1 rounded-full bg-primary-container font-label-md text-label-md text-primary-fixed">
            <Icono nombre="calendar_clock" tamano="sm" />
            {datos.insignia}
          </span>
        )}
        <h2 id="titulo-cta" className="font-headline-lg-mobile text-headline-lg-mobile md:font-headline-lg md:text-headline-lg">
          {datos.titulo}
        </h2>
        {datos.descripcion && <p className="font-body-lg text-body-lg text-primary-fixed max-w-3xl">{datos.descripcion}</p>}
        <div className="flex flex-wrap justify-center items-center gap-space-md pt-space-sm">
          <Link href="/?origen=cta_final#demo" className="px-space-lg py-space-sm rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-label-lg text-label-lg flex items-center gap-space-xs shadow-md">
            <Icono nombre="event_available" tamano="lg" />
            {datos.boton}
          </Link>
          <span className="flex items-center gap-space-xs font-label-lg text-label-lg text-primary-fixed">
            <Icono nombre="call" />
            {landing.central} {oConfirmar(telefono)}
          </span>
        </div>
        {datos.beneficios.length > 0 && (
          <ul className="flex flex-wrap justify-center gap-space-md pt-space-sm font-label-md text-label-md text-primary-fixed">
            {datos.beneficios.map((b) => (
              <li key={b} className="flex items-center gap-space-xs">
                <Icono nombre="check" tamano="sm" className="text-tertiary-fixed" />
                {b}
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}

/**
 * EDUN-263 · Footer con datos legales. Privacidad, términos y Libro de Reclamaciones vienen
 * como enlaces fijos del CMS. Los datos vacíos dicen «[A CONFIRMAR]». El año es el actual.
 */
export function Pie({ datos, config, enlaces }: { datos: SeccionFooter; config: Config; enlaces: ItemMenu[] }) {
  const columnas = Array.from(new Set(enlaces.map((e) => e.columna ?? landing.otros)));
  const anio = new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', year: 'numeric' }).format(new Date());
  return (
    <footer className="w-full bg-inverse-surface text-inverse-on-surface pt-space-2xl pb-space-lg">
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-space-xl">
        <div className="flex flex-col gap-space-md lg:col-span-1">
          <span className="flex items-center gap-space-sm">
            <span aria-hidden="true" className="w-10 h-10 rounded-lg bg-primary flex items-center justify-center font-headline-md text-headline-md text-on-primary font-bold">
              {sitio.marca.charAt(0)}
            </span>
            <span className="font-headline-sm text-headline-sm font-semibold">{sitio.marca}</span>
          </span>
          <p className="font-body-sm text-body-sm text-inverse-on-surface/80">{datos.descripcion}</p>
          <address className="not-italic flex flex-col gap-space-xs font-body-sm text-body-sm">
            <span className="flex items-start gap-space-xs">
              <Icono nombre="location_on" tamano="sm" className="mt-0.5" />
              {landing.direccion} {oConfirmar(config.direccion)}
            </span>
            <span className="flex items-center gap-space-xs">
              <Icono nombre="call" tamano="sm" />
              {landing.central} {oConfirmar(config.telefono_central)}
            </span>
            <span className="flex items-center gap-space-xs">
              <Icono nombre="mail" tamano="sm" />
              {oConfirmar(config.email_contacto)}
            </span>
          </address>
        </div>
        {columnas.map((col) => (
          <nav key={col} aria-label={col} className="flex flex-col gap-space-sm">
            <h2 className="font-title-md text-title-md">{col}</h2>
            <ul className="flex flex-col gap-space-xs font-body-sm text-body-sm">
              {enlaces
                .filter((e) => (e.columna ?? landing.otros) === col && e.url !== '/libro-de-reclamaciones')
                .map((e) => (
                  <li key={e.url}>
                    <a href={e.url} className="text-inverse-on-surface/80 hover:text-inverse-on-surface hover:underline">
                      {e.label}
                    </a>
                  </li>
                ))}
            </ul>
          </nav>
        ))}
        <div className="flex flex-col gap-space-sm">
          <h2 className="font-title-md text-title-md">{landing.atencionUsuario}</h2>
          <Link
            href="/libro-de-reclamaciones"
            className="flex items-center gap-space-sm p-space-md rounded-xl border border-inverse-on-surface/30 hover:bg-primary-container"
          >
            <Icono nombre="menu_book" tamano="lg" />
            <span className="flex flex-col">
              <span className="font-title-md text-title-md">{landing.libroReclamaciones}</span>
              <span className="font-label-sm text-label-sm text-inverse-on-surface/80">{landing.leyConsumidor}</span>
            </span>
          </Link>
          <p className="flex items-center gap-space-xs font-label-md text-label-md">
            <Icono nombre="check_circle" tamano="sm" />
            RUC: {oConfirmar(config.ruc)}
          </p>
        </div>
      </div>
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin mt-space-xl pt-space-lg border-t border-inverse-on-surface/20 flex flex-col md:flex-row justify-between gap-space-sm font-label-sm text-label-sm text-inverse-on-surface/80">
        <p>
          © {anio} {oConfirmar(config.razon_social)}. {datos.derechos}
        </p>
        <p>
          {datos.moneda} · {landing.horario} {oConfirmar(config.horario_soporte)}
        </p>
      </div>
    </footer>
  );
}
