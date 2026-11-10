import Link from 'next/link';
import { Icono } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import { leerDashboard, type Dashboard } from '../panel-acciones';

// EDUN-383 / SIT-029 · Dashboard del CMS: solicitudes, demos y reclamaciones, tendencia de
// 30 días y accesos directos a lo pendiente. Visitas y conversión no se muestran hasta que
// haya una fuente real (Google Analytics): nada sin respaldo (TRASPASO §7).
export const dynamic = 'force-dynamic';

const t = cms.dashboard;
const diaCorto = (d: string) => new Intl.DateTimeFormat('es-PE', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${d}T00:00:00Z`));
const fecha = (iso: string) => new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));

function Tarjeta({ titulo, valor, nota, icono, href, alerta = false }: { titulo: string; valor: string; nota: string; icono: string; href?: string; alerta?: boolean }) {
  const cuerpo = (
    <>
      <span className="flex items-center justify-between font-label-md text-label-md text-secondary">
        {titulo}
        <Icono nombre={icono} className={alerta ? 'text-error' : 'text-primary'} />
      </span>
      <span className={`font-headline-md text-headline-md font-bold ${alerta ? 'text-error' : 'text-primary'}`}>{valor}</span>
      <span className="font-label-sm text-label-sm text-on-surface-variant">{nota}</span>
    </>
  );
  const clase = 'h-full p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-1';
  return <li>{href ? <Link href={href} className={`${clase} hover:border-primary`}>{cuerpo}</Link> : <div className={clase}>{cuerpo}</div>}</li>;
}

function Tendencia({ datos }: { datos: Dashboard['tendencia'] }) {
  const max = Math.max(1, ...datos.map((d) => d.leads));
  const total = datos.reduce((s, d) => s + d.leads, 0);
  return (
    <figure className="flex flex-col gap-space-sm">
      <figcaption className="flex flex-wrap items-baseline justify-between gap-space-xs">
        <span className="font-title-md text-title-md text-primary">{t.tendencia}</span>
        <span className="font-label-md text-label-md text-secondary">{t.tendenciaResumen(total)}</span>
      </figcaption>
      <div aria-hidden="true" className="h-40 flex items-end gap-[2px]">
        {datos.map((d) => (
          <div key={d.dia} title={`${diaCorto(d.dia)}: ${d.leads}`} className="flex-1 rounded-t bg-primary/80 hover:bg-primary min-h-[2px]" style={{ height: `${(d.leads / max) * 100}%` }} />
        ))}
      </div>
      <div aria-hidden="true" className="flex justify-between font-label-sm text-label-sm text-on-surface-variant">
        <span>{diaCorto(datos[0]?.dia ?? '')}</span>
        <span>{diaCorto(datos[datos.length - 1]?.dia ?? '')}</span>
      </div>
      <table className="sr-only">
        <caption>{t.tendencia}</caption>
        <tbody>
          {datos.map((d) => (
            <tr key={d.dia}>
              <th scope="row">{diaCorto(d.dia)}</th>
              <td>{d.leads}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  );
}

export default async function InicioCms() {
  const d = await leerDashboard();

  if (!d) {
    return (
      <div className="px-margin-mobile md:px-margin py-space-lg">
        <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
        <p role="alert" className="mt-space-md font-body-md text-body-md text-error">
          {t.errorCarga}
        </p>
      </div>
    );
  }

  const pendientes = [
    d.borradores.length > 0 && { texto: t.borradores(d.borradores.length), href: '/cms/auditoria?accion=borrador', icono: 'edit_note' },
    d.leads_sin_contactar > 0 && { texto: t.leadsNuevos(d.leads_sin_contactar), href: '/cms/leads/bandeja?estado=nuevo', icono: 'mark_email_unread' },
    d.reclamos.vencidos > 0 && { texto: t.reclamosVencidos(d.reclamos.vencidos), href: '/cms/reclamaciones?plazo=vencidos', icono: 'error' },
    d.reclamos.por_vencer > 0 && { texto: t.reclamosPorVencer(d.reclamos.por_vencer), href: '/cms/reclamaciones?plazo=por_vencer', icono: 'schedule' },
    d.crm_pendientes > 0 && { texto: t.crm(d.crm_pendientes), href: '/cms/leads/bandeja', icono: 'sync' },
  ].filter((x): x is { texto: string; href: string; icono: string } => Boolean(x));
  const totalOrigen = Object.values(d.por_origen).reduce((s, n) => s + n, 0);

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <header className="flex flex-col gap-1">
        <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
        <p className="font-body-md text-body-md text-on-surface-variant">{t.descripcion}</p>
      </header>

      <ul className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-space-md">
        <Tarjeta titulo={t.leadsHoy} valor={String(d.leads_hoy)} nota={t.sinContactar(d.leads_sin_contactar)} icono="inbox" href="/cms/leads/bandeja" />
        <Tarjeta titulo={t.leads7d} valor={String(d.leads_7d)} nota={t.variacion(d.leads_7d, d.leads_7d_previos)} icono="trending_up" />
        <Tarjeta titulo={t.demosHoy} valor={String(d.demos_hoy)} nota={t.demosNota} icono="event_available" href="/cms/leads/bandeja" />
        <Tarjeta
          titulo={t.reclamos}
          valor={String(d.reclamos.abiertos)}
          nota={t.reclamosNota(d.reclamos.vencidos, d.reclamos.por_vencer)}
          icono="gavel"
          href="/cms/reclamaciones"
          alerta={d.reclamos.vencidos > 0}
        />
      </ul>
      <p className="font-label-md text-label-md text-on-surface-variant">{t.sinVisitas}</p>

      <div className="grid grid-cols-1 xl:grid-cols-[2fr_1fr] gap-space-md items-start">
        <section className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-md">
          <Tendencia datos={d.tendencia} />
          <div className="flex flex-col gap-space-xs">
            <h2 className="font-title-md text-title-md text-primary">{t.porOrigen}</h2>
            <ul className="flex flex-col gap-1">
              {Object.entries(d.por_origen).map(([origen, n]) => (
                <li key={origen} className="grid grid-cols-[8rem_1fr_3rem] items-center gap-space-sm font-label-md text-label-md">
                  <span className="text-secondary">{t.origenes[origen] ?? origen}</span>
                  <span aria-hidden="true" className="h-2 rounded-full bg-surface-container overflow-hidden">
                    <span className="block h-full bg-accion" style={{ width: `${totalOrigen ? (n / totalOrigen) * 100 : 0}%` }} />
                  </span>
                  <span className="text-right text-on-surface">{n}</span>
                </li>
              ))}
            </ul>
          </div>
        </section>

        <div className="flex flex-col gap-space-md">
          <section aria-labelledby="titulo-pendientes" className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-sm">
            <h2 id="titulo-pendientes" className="font-title-md text-title-md text-primary">
              {t.pendientes}
            </h2>
            {pendientes.length === 0 ? (
              <p className="font-body-sm text-body-sm text-on-surface-variant">{t.sinPendientes}</p>
            ) : (
              <ul className="flex flex-col gap-1">
                {pendientes.map((p) => (
                  <li key={p.href + p.texto}>
                    <Link href={p.href} className="flex items-center gap-space-sm p-space-sm rounded-lg hover:bg-surface-container-low font-body-sm text-body-sm text-on-surface">
                      <Icono nombre={p.icono} className="text-primary" tamano="sm" />
                      {p.texto}
                      <Icono nombre="chevron_right" className="ml-auto text-secondary" tamano="sm" />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section aria-labelledby="titulo-ultimos" className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-sm">
            <h2 id="titulo-ultimos" className="font-title-md text-title-md text-primary">
              {t.ultimos}
            </h2>
            <ul className="flex flex-col divide-y divide-surface-container">
              {d.ultimos_leads.map((l) => (
                <li key={l.id} className="py-space-xs flex flex-col">
                  <span className="font-body-sm text-body-sm text-on-surface">{l.colegio ?? l.email ?? l.telefono}</span>
                  <span className="font-label-sm text-label-sm text-secondary">
                    {t.origenes[l.source] ?? l.source} · {fecha(l.created_at)}
                  </span>
                </li>
              ))}
            </ul>
            <Link href="/cms/leads/bandeja" className="self-start font-label-md text-label-md text-primary underline">
              {t.verBandeja}
            </Link>
          </section>
        </div>
      </div>
    </div>
  );
}
