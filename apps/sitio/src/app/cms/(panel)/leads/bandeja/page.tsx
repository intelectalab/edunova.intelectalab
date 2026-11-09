import Link from 'next/link';
import { Icono, Insignia, type TonoInsignia } from '@/componentes/base';
import { bandeja as t } from '@/contenido/provisional';
import { soles } from '@/lib/formato';
import { leerBandeja, type Bandeja, type FiltrosBandeja, type Lead } from '../../../leads-acciones';
import { CambiarEstado } from './CambiarEstado';

// EDUN-278 / SIT-026 · Bandeja de solicitudes de demo (prototipo #cms-bandeja-solicitudes).
// Filtros, pestañas y página viajan en la URL: se pueden compartir y funcionan sin JavaScript.
// Sin «cotejado con el Padrón MINEDU/SUNAT» ni «Sincronizado SIAGIE» (TRASPASO §7).
export const dynamic = 'force-dynamic';

const TONOS: Record<string, TonoInsignia> = { nuevo: 'info', contactado: 'aviso', en_proceso: 'aviso', ganado: 'exito', perdido: 'neutro' };
const ESTADOS = ['todos', 'nuevo', 'contactado', 'en_proceso', 'ganado', 'perdido'] as const;

const fecha = (iso: string) => new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
const fechaDia = (d: string) => d.split('-').reverse().join('/');

function url(f: FiltrosBandeja & { id?: string }, cambios: Partial<FiltrosBandeja & { id: string }>) {
  const p = new URLSearchParams(Object.entries({ ...f, ...cambios }).filter((e): e is [string, string] => typeof e[1] === 'string' && e[1] !== ''));
  const q = p.toString();
  return `/cms/leads/bandeja${q ? `?${q}` : ''}`;
}

function whatsapp(tel: string | null) {
  const n = (tel ?? '').replace(/\D/g, '');
  if (n.length < 9) return null;
  return `https://wa.me/${n.length === 9 ? `51${n}` : n}`;
}

function Indicadores({ i }: { i: Bandeja['indicadores'] }) {
  const tarjetas = [
    { titulo: t.indicadores.totalMes, valor: String(i.total_mes), nota: t.indicadores.sinContactar(i.sin_contactar), icono: 'inbox' },
    { titulo: t.indicadores.respuesta, valor: i.respuesta_horas === null ? t.indicadores.sinDatos : t.indicadores.respuestaValor(i.respuesta_horas), nota: t.indicadores.respuestaNota, icono: 'timer' },
    { titulo: t.indicadores.demos, valor: String(i.demos_agendadas), nota: t.indicadores.demosHoy(i.demos_hoy), icono: 'event_available' },
    { titulo: t.indicadores.pipeline, valor: soles(i.pipeline_mensual), nota: t.indicadores.pipelineNota(i.pipeline_leads), icono: 'payments' },
  ];
  return (
    <ul className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-space-md">
      {tarjetas.map((c) => (
        <li key={c.titulo} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-1">
          <span className="flex items-center justify-between font-label-md text-label-md text-secondary">
            {c.titulo}
            <Icono nombre={c.icono} className="text-primary" />
          </span>
          <span className="font-headline-md text-headline-md text-primary font-bold" data-indicador={c.icono}>
            {c.valor}
          </span>
          <span className="font-label-sm text-label-sm text-on-surface-variant">{c.nota}</span>
        </li>
      ))}
    </ul>
  );
}

function Ficha({ lead }: { lead: Lead | undefined }) {
  if (!lead) {
    return (
      <aside aria-label={t.ficha} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container">
        <h2 className="font-title-lg text-title-lg text-primary">{t.ficha}</h2>
        <p className="font-body-sm text-body-sm text-on-surface-variant mt-space-sm">{t.fichaVacia}</p>
      </aside>
    );
  }
  const datos: [string, string | null][] = [
    [t.contacto, [lead.nombre, lead.cargo].filter(Boolean).join(' · ')],
    [t.demo, lead.demo_fecha ? t.demoValor(fechaDia(lead.demo_fecha), lead.demo_hora) : t.demoSin],
    [t.columnas.alumnos, lead.alumnos ? lead.alumnos.toLocaleString('es-PE') : '—'],
    [t.columnas.plan, lead.plan_nombre ?? t.sinPlan],
    [t.recibida, fecha(lead.created_at)],
    [t.origen, [lead.source, lead.utm_source, lead.utm_medium].filter(Boolean).join(' · ')],
    [t.campana, lead.utm_campaign],
    [t.paginaOrigen, lead.page_url],
    [t.mensaje, lead.mensaje],
  ];
  return (
    <aside aria-label={t.ficha} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-sm">
      <h2 className="font-title-lg text-title-lg text-primary">{t.ficha}</h2>
      <div>
        <p className="font-title-md text-title-md text-on-surface">{lead.colegio}</p>
        <p className="font-label-md text-label-md text-secondary">{lead.ciudad ?? t.sinCiudad}</p>
      </div>
      <dl className="grid grid-cols-1 gap-space-xs font-body-sm text-body-sm">
        {datos
          .filter(([, v]) => v)
          .map(([k, v]) => (
            <div key={k} className="flex flex-col">
              <dt className="font-label-sm text-label-sm text-secondary uppercase tracking-wider">{k}</dt>
              <dd className="text-on-surface break-words">{v}</dd>
            </div>
          ))}
      </dl>
      <div className="flex flex-wrap gap-space-xs">
        {lead.email && (
          <a href={`mailto:${lead.email}`} className="inline-flex items-center gap-1 px-space-sm h-9 rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-md text-label-md">
            <Icono nombre="mail" tamano="sm" />
            {lead.email}
          </a>
        )}
        {lead.telefono && (
          <a href={`tel:${lead.telefono.replace(/\s/g, '')}`} className="inline-flex items-center gap-1 px-space-sm h-9 rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-md text-label-md">
            <Icono nombre="call" tamano="sm" />
            {lead.telefono}
          </a>
        )}
      </div>
      <CambiarEstado key={`${lead.id}-${lead.estado}`} id={lead.id} estado={lead.estado} />
    </aside>
  );
}

export default async function PaginaBandeja({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const sp = await searchParams;
  const f: FiltrosBandeja = { estado: sp.estado, plan: sp.plan, ciudad: sp.ciudad, q: sp.q, pagina: sp.pagina };
  const datos = await leerBandeja(f);

  if (!datos) {
    return (
      <div className="px-margin-mobile md:px-margin py-space-lg">
        <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
        <p role="alert" className="mt-space-md font-body-md text-body-md text-error">
          {t.errorCarga}
        </p>
      </div>
    );
  }

  const activo = f.estado ?? 'todos';
  const seleccionado = datos.data.find((l) => String(l.id) === sp.id) ?? datos.data[0];
  const desde = datos.total === 0 ? 0 : (datos.pagina - 1) * datos.por_pagina + 1;
  const hasta = Math.min(datos.pagina * datos.por_pagina, datos.total);
  const sinPagina = { ...f, pagina: undefined, id: undefined };

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <header className="flex flex-col gap-1">
        <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
        <p className="font-body-md text-body-md text-on-surface-variant">{t.descripcion}</p>
      </header>

      <Indicadores i={datos.indicadores} />

      <nav aria-label={t.estadosNav} className="flex gap-space-xs overflow-x-auto border-b border-surface-container">
        {ESTADOS.map((e) => (
          <Link
            key={e}
            href={url(sinPagina, { estado: e === 'todos' ? undefined : e })}
            aria-current={activo === e ? 'page' : undefined}
            className={`shrink-0 px-space-md py-space-sm font-label-lg text-label-lg border-b-2 -mb-px ${activo === e ? 'border-primary text-primary' : 'border-transparent text-secondary hover:text-primary'}`}
          >
            {t.pestanas[e]} <span className="ml-1 px-2 rounded-full bg-surface-container font-label-sm text-label-sm">{datos.contadores[e]}</span>
          </Link>
        ))}
      </nav>

      <form method="get" role="search" aria-label={t.filtros} className="flex flex-col md:flex-row md:items-end gap-space-sm">
        {f.estado && <input type="hidden" name="estado" value={f.estado} />}
        <label className="flex flex-col gap-1 flex-1 font-label-md text-label-md text-secondary">
          {t.buscar}
          <input name="q" defaultValue={f.q} placeholder={t.buscarEjemplo} className="h-10 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 font-body-md text-body-md text-on-surface" />
        </label>
        <label className="flex flex-col gap-1 font-label-md text-label-md text-secondary">
          {t.ciudad}
          <select name="ciudad" defaultValue={f.ciudad ?? ''} className="h-10 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface">
            <option value="">{t.todasCiudades}</option>
            {datos.ciudades.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1 font-label-md text-label-md text-secondary">
          {t.plan}
          <select name="plan" defaultValue={f.plan ?? ''} className="h-10 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface">
            <option value="">{t.todosPlanes}</option>
            {datos.planes.map((p) => (
              <option key={p.clave} value={p.clave}>
                {p.nombre}
              </option>
            ))}
          </select>
        </label>
        <div className="flex gap-space-xs">
          <button type="submit" className="h-10 px-space-md rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg">
            {t.aplicar}
          </button>
          <Link href="/cms/leads/bandeja" className="h-10 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg inline-flex items-center">
            {t.limpiar}
          </Link>
        </div>
      </form>

      <div className="grid grid-cols-1 xl:grid-cols-[1fr_20rem] gap-space-md items-start">
        <div className="flex flex-col gap-space-sm min-w-0">
          <div className="rounded-xl overflow-hidden border border-surface-container bg-surface-container-lowest">
            <div className="overflow-x-auto">
              <table className="w-full border-collapse">
                <caption className="sr-only">{t.tabla}</caption>
                <thead>
                  <tr className="bg-surface-container-low text-secondary font-label-md text-label-md uppercase tracking-wider h-11 text-left">
                    {Object.values(t.columnas).map((c) => (
                      <th key={c} scope="col" className="py-3 px-space-md font-semibold whitespace-nowrap">
                        {c}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-surface-container font-body-sm text-body-sm text-on-surface">
                  {datos.data.length === 0 ? (
                    <tr className="h-fila-tabla">
                      <td colSpan={6} className="px-space-md text-center text-on-surface-variant">
                        {t.vacio}
                      </td>
                    </tr>
                  ) : (
                    datos.data.map((l) => {
                      const wa = whatsapp(l.telefono);
                      const nombre = l.colegio ?? '—';
                      return (
                        <tr key={l.id} data-lead={l.id} aria-selected={seleccionado?.id === l.id} className={`h-fila-tabla ${seleccionado?.id === l.id ? 'bg-surface-container-low' : 'hover:bg-surface-container-low'}`}>
                          <td className="px-space-md py-space-xs">
                            <span className="block font-semibold">{nombre}</span>
                            <span className="block font-label-sm text-label-sm text-secondary">
                              {l.ciudad ?? t.sinCiudad} · {fecha(l.created_at)}
                            </span>
                          </td>
                          <td className="px-space-md py-space-xs">
                            <span className="block">{l.nombre}</span>
                            <span className="block font-label-sm text-label-sm text-secondary">{l.cargo}</span>
                          </td>
                          <td className="px-space-md">{l.alumnos ? l.alumnos.toLocaleString('es-PE') : '—'}</td>
                          <td className="px-space-md whitespace-nowrap">
                            <span className="block">{l.plan_nombre ?? t.sinPlan}</span>
                            {l.precio_mensual && (
                              <span className="block font-label-sm text-label-sm text-secondary">
                                {soles(l.precio_mensual)} {t.porMes}
                              </span>
                            )}
                          </td>
                          <td className="px-space-md">
                            <Insignia tono={TONOS[l.estado]}>{t.estados[l.estado]}</Insignia>
                          </td>
                          <td className="px-space-md">
                            <span className="flex items-center gap-1">
                              {wa && (
                                <a href={wa} target="_blank" rel="noopener" aria-label={t.whatsapp(nombre)} className="w-8 h-8 rounded-lg hover:bg-surface-container flex items-center justify-center text-accion">
                                  <Icono nombre="chat" tamano="sm" />
                                </a>
                              )}
                              {l.email && (
                                <a href={`mailto:${l.email}`} aria-label={t.correo(nombre)} className="w-8 h-8 rounded-lg hover:bg-surface-container flex items-center justify-center text-primary">
                                  <Icono nombre="mail" tamano="sm" />
                                </a>
                              )}
                              <Link href={url(f, { id: String(l.id) })} scroll={false} aria-label={t.verFicha(nombre)} className="w-8 h-8 rounded-lg hover:bg-surface-container flex items-center justify-center text-primary">
                                <Icono nombre="more_vert" tamano="sm" />
                              </Link>
                            </span>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
          <nav aria-label={t.paginacion} className="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm font-label-md text-label-md text-secondary">
            <span>{t.mostrando(desde, hasta, datos.total)}</span>
            <span className="flex items-center gap-space-xs">
              {datos.pagina > 1 ? (
                <Link href={url(f, { pagina: String(datos.pagina - 1), id: undefined })} aria-label={t.anterior} className="w-9 h-9 rounded-lg hover:bg-surface-container flex items-center justify-center text-primary">
                  <Icono nombre="chevron_left" />
                </Link>
              ) : null}
              <span>{t.pagina(datos.pagina, datos.paginas)}</span>
              {datos.pagina < datos.paginas ? (
                <Link href={url(f, { pagina: String(datos.pagina + 1), id: undefined })} aria-label={t.siguiente} className="w-9 h-9 rounded-lg hover:bg-surface-container flex items-center justify-center text-primary">
                  <Icono nombre="chevron_right" />
                </Link>
              ) : null}
            </span>
          </nav>
        </div>
        <Ficha lead={seleccionado} />
      </div>
    </div>
  );
}
