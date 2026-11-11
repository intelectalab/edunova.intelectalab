import Link from 'next/link';
import { Insignia } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import { leerSesionChatbot, leerSesionesChatbot, type FiltrosSesiones } from '../../../../panel-acciones';

// EDUN-381 / SIT-028 · Historial de sesiones del chatbot con filtros (fecha, resultado, contacto y
// texto) y la conversación completa al elegir una. Boceto propio con el sistema de diseño del panel.
export const dynamic = 'force-dynamic';

const t = cms.chatbotSesiones;
const fecha = (iso: string) => new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
const TONOS: Record<string, 'exito' | 'info' | 'aviso' | 'neutro'> = { captura: 'exito', whatsapp: 'exito', url: 'info', fin: 'info', abandono: 'neutro' };
const CAMPO = 'h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface';

export default async function SesionesChatbot({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const p = await searchParams;
  const f: FiltrosSesiones = { desde: p.desde, hasta: p.hasta, resultado: p.resultado, lead: p.lead, q: p.q, pagina: p.pagina };
  const [lista, detalle] = await Promise.all([leerSesionesChatbot(f), p.sesion ? leerSesionChatbot(p.sesion) : Promise.resolve(null)]);
  const enlace = (cambios: Record<string, string | undefined>) => {
    const q = new URLSearchParams(Object.entries({ ...f, ...cambios }).filter((e): e is [string, string] => !!e[1])).toString();
    return `/cms/conversion/chatbot/sesiones${q ? `?${q}` : ''}`;
  };

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <div className="flex flex-wrap items-start justify-between gap-space-sm">
        <div className="flex flex-col gap-space-xs max-w-2xl">
          <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
          <p className="font-body-md text-body-md text-on-surface-variant">{t.descripcion}</p>
        </div>
        <Link href="/cms/conversion/chatbot" className="font-label-lg text-label-lg text-primary underline">
          {t.volver}
        </Link>
      </div>

      <form key={JSON.stringify(f)} method="get" aria-label={t.filtros} className="grid gap-space-sm sm:grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_minmax(0,1.5fr)_auto] items-end p-space-md rounded-xl bg-surface-container-lowest border border-surface-container">
        <label className="flex flex-col gap-space-xs font-label-md text-label-md text-on-surface">
          {t.desde}
          <input type="date" name="desde" defaultValue={f.desde} className={CAMPO} />
        </label>
        <label className="flex flex-col gap-space-xs font-label-md text-label-md text-on-surface">
          {t.hasta}
          <input type="date" name="hasta" defaultValue={f.hasta} className={CAMPO} />
        </label>
        <label className="flex flex-col gap-space-xs font-label-md text-label-md text-on-surface">
          {t.resultado}
          <select name="resultado" defaultValue={f.resultado ?? ''} className={CAMPO}>
            <option value="">{t.todos}</option>
            {Object.entries(lista?.resultados ?? {}).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-space-xs font-label-md text-label-md text-on-surface">
          {t.contacto}
          <select name="lead" defaultValue={f.lead ?? ''} className={CAMPO}>
            {Object.entries(t.contactoOpciones).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-space-xs font-label-md text-label-md text-on-surface">
          {t.buscar}
          <input type="search" name="q" defaultValue={f.q} maxLength={100} className={CAMPO} />
        </label>
        <div className="flex gap-space-sm">
          <button type="submit" className="h-11 px-space-md rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg">
            {t.filtrar}
          </button>
          <Link href="/cms/conversion/chatbot/sesiones" className="h-11 inline-flex items-center px-space-md rounded-lg bg-surface-container-low text-primary font-label-lg text-label-lg">
            {t.limpiar}
          </Link>
        </div>
      </form>

      {!lista ? (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {t.errorCarga}
        </p>
      ) : (
        <div className={`grid gap-space-md items-start ${detalle ? 'xl:grid-cols-[minmax(0,1fr)_26rem]' : ''}`}>
          <section aria-labelledby="titulo-sesiones" className="flex flex-col gap-space-sm min-w-0">
            <div className="flex flex-wrap items-center gap-space-xs">
              <h2 id="titulo-sesiones" className="font-title-md text-title-md text-primary mr-space-sm">
                {t.total(lista.total)}
              </h2>
              {Object.entries(lista.por_resultado).map(([k, n]) => (
                <Insignia key={k} tono={TONOS[k] ?? 'neutro'}>
                  {lista.resultados[k]}: {n}
                </Insignia>
              ))}
            </div>
            {lista.data.length === 0 ? (
              <p className="px-space-md py-space-lg rounded-xl border border-dashed border-outline-variant text-center font-body-md text-body-md text-on-surface-variant">{t.vacio}</p>
            ) : (
              <ul className="flex flex-col gap-space-sm">
                {lista.data.map((s) => (
                  <li key={s.id} className={`p-space-md rounded-xl border bg-surface-container-lowest flex flex-col gap-space-xs ${String(s.id) === p.sesion ? 'border-primary' : 'border-surface-container'}`}>
                    <div className="flex flex-wrap items-center gap-space-xs">
                      <Insignia tono={TONOS[s.resultado] ?? 'neutro'}>{lista.resultados[s.resultado] ?? s.resultado}</Insignia>
                      <span className="font-label-sm text-label-sm text-secondary">{[fecha(s.created_at), s.pagina, t.pasos(s.pasos)].filter(Boolean).join(' · ')}</span>
                    </div>
                    {s.ultimo && <p className="font-body-sm text-body-sm text-on-surface line-clamp-2 break-words">{s.ultimo}</p>}
                    <Link href={enlace({ sesion: String(s.id) })} aria-label={`${t.ver} · ${fecha(s.created_at)}`} className="self-start font-label-md text-label-md text-primary underline">
                      {t.ver}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
            {lista.paginas > 1 && (
              <nav aria-label={t.pagina(lista.pagina, lista.paginas)} className="flex items-center gap-space-sm font-label-md text-label-md">
                {lista.pagina > 1 && <Link href={enlace({ pagina: String(lista.pagina - 1), sesion: undefined })} className="text-primary underline">{t.anterior}</Link>}
                <span className="text-secondary">{t.pagina(lista.pagina, lista.paginas)}</span>
                {lista.pagina < lista.paginas && <Link href={enlace({ pagina: String(lista.pagina + 1), sesion: undefined })} className="text-primary underline">{t.siguiente}</Link>}
              </nav>
            )}
          </section>

          {detalle && (
            <section aria-labelledby="titulo-conversacion" className="xl:sticky xl:top-24 rounded-xl border border-surface-container bg-surface-container-lowest flex flex-col min-w-0">
              <div className="flex items-center justify-between gap-space-sm px-space-md py-space-sm bg-surface-container-low rounded-t-xl">
                <h2 id="titulo-conversacion" className="font-title-md text-title-md text-primary">
                  {t.conversacion}
                </h2>
                <Link href={enlace({ sesion: undefined })} className="font-label-md text-label-md text-secondary underline">
                  {t.cerrar}
                </Link>
              </div>
              <div className="p-space-md flex flex-col gap-space-sm">
                <p className="font-label-sm text-label-sm text-secondary">{[fecha(detalle.created_at), detalle.pagina, lista.resultados[detalle.resultado]].filter(Boolean).join(' · ')}</p>
                <ol aria-label={t.conversacion} className="flex flex-col gap-space-xs">
                  {detalle.mensajes.map((m, i) => (
                    <li key={i} className={`max-w-[90%] px-space-sm py-space-xs rounded-xl font-body-sm text-body-sm whitespace-pre-line break-words ${m.rol === 'visitante' ? 'self-end bg-primary text-on-primary' : 'self-start bg-surface-container text-on-surface'}`}>
                      {m.contenido}
                    </li>
                  ))}
                </ol>
                {detalle.lead_capturado && (
                  <div className="flex flex-col gap-1 p-space-sm rounded-lg bg-surface-container-low font-body-sm text-body-sm text-on-surface">
                    <span className="font-label-md text-label-md text-on-surface-variant">{t.lead}</span>
                    {[detalle.lead_nombre, detalle.lead_email, detalle.lead_telefono].filter(Boolean).map((x) => (
                      <span key={x} className="break-words">{x}</span>
                    ))}
                    <Link href="/cms/leads/bandeja" className="font-label-md text-label-md text-primary underline">
                      {t.irBandeja}
                    </Link>
                  </div>
                )}
              </div>
            </section>
          )}
        </div>
      )}
    </div>
  );
}
