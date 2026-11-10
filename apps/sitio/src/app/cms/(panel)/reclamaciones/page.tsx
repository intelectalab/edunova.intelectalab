import Link from 'next/link';
import { Icono, Insignia, type TonoInsignia } from '@/componentes/base';
import { gestionReclamos as t } from '@/contenido/provisional';
import { leerReclamaciones, leerReclamo, type FiltrosReclamos, type ListaReclamos, type Reclamo } from '../../reclamaciones-acciones';
import { GestionReclamo } from './GestionReclamo';

// EDUN-302 / SIT-031 · Gestión del Libro de Reclamaciones (Ley N.° 29571, D.S. N.° 011-2011-PCM).
// Filtros, pestañas, página y expediente elegido viajan en la URL (funcionan sin JavaScript).
export const dynamic = 'force-dynamic';

const ESTADOS = ['todos', 'recibido', 'en_revision', 'respondido', 'cerrado'] as const;
const TONOS: Record<string, TonoInsignia> = { recibido: 'info', en_revision: 'aviso', respondido: 'exito', cerrado: 'neutro' };
const TONOS_PLAZO: Record<string, TonoInsignia> = { vencido: 'error', por_vencer: 'aviso', en_plazo: 'neutro', a_tiempo: 'exito', fuera_de_plazo: 'error' };

const fechaHora = (iso: string) => new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
const fechaDia = (d: string) => d.slice(0, 10).split('-').reverse().join('/');
const tamano = (b: number) => (b >= 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

function url(f: FiltrosReclamos & { id?: string }, cambios: Partial<FiltrosReclamos & { id: string }>) {
  const p = new URLSearchParams(Object.entries({ ...f, ...cambios }).filter((e): e is [string, string] => typeof e[1] === 'string' && e[1] !== ''));
  const q = p.toString();
  return `/cms/reclamaciones${q ? `?${q}` : ''}`;
}

function Indicadores({ i, f }: { i: ListaReclamos['indicadores']; f: FiltrosReclamos }) {
  const tarjetas = [
    { clave: 'abiertos', titulo: t.indicadores.abiertos, valor: String(i.abiertos), nota: t.indicadores.abiertosNota, icono: 'folder_open', href: null },
    { clave: 'vencidos', titulo: t.indicadores.vencidos, valor: String(i.vencidos), nota: t.indicadores.vencidosNota, icono: 'error', href: url({}, { plazo: 'vencidos' }) },
    { clave: 'por_vencer', titulo: t.indicadores.porVencer, valor: String(i.por_vencer), nota: t.indicadores.porVencerNota, icono: 'schedule', href: url({}, { plazo: 'por_vencer' }) },
    {
      clave: 'a_tiempo',
      titulo: t.indicadores.aTiempo,
      valor: i.a_tiempo_pct === null ? t.indicadores.sinDatos : t.indicadores.aTiempoValor(i.a_tiempo_pct),
      nota: t.indicadores.aTiempoNota(i.respondidos_90),
      icono: 'task_alt',
      href: null,
    },
  ];
  return (
    <ul className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-space-md">
      {tarjetas.map((c) => {
        const contenido = (
          <>
            <span className="flex items-center justify-between font-label-md text-label-md text-secondary">
              {c.titulo}
              <Icono nombre={c.icono} className={c.clave === 'vencidos' && i.vencidos > 0 ? 'text-error' : 'text-primary'} />
            </span>
            <span className={`font-headline-md text-headline-md font-bold ${c.clave === 'vencidos' && i.vencidos > 0 ? 'text-error' : 'text-primary'}`} data-indicador={c.clave}>
              {c.valor}
            </span>
            <span className="font-label-sm text-label-sm text-on-surface-variant">{c.nota}</span>
          </>
        );
        const clase = 'p-space-md rounded-xl bg-surface-container-lowest border flex flex-col gap-1 h-full';
        const activa = c.href && f.plazo === c.clave;
        return (
          <li key={c.clave}>
            {c.href ? (
              <Link href={c.href} aria-current={activa ? 'true' : undefined} className={`${clase} hover:border-primary ${activa ? 'border-primary' : 'border-surface-container'}`}>
                {contenido}
              </Link>
            ) : (
              <div className={`${clase} border-surface-container`}>{contenido}</div>
            )}
          </li>
        );
      })}
    </ul>
  );
}

function Dato({ k, v }: { k: string; v: string | null | undefined }) {
  if (!v) return null;
  return (
    <div className="flex flex-col">
      <dt className="font-label-sm text-label-sm text-secondary uppercase tracking-wider">{k}</dt>
      <dd className="text-on-surface break-words whitespace-pre-line">{v}</dd>
    </div>
  );
}

function Ficha({ r, error }: { r: Reclamo | null; error: boolean }) {
  if (!r) {
    return (
      <aside aria-label={t.ficha} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container">
        <h2 className="font-title-lg text-title-lg text-primary">{t.ficha}</h2>
        <p role={error ? 'alert' : undefined} className={`font-body-sm text-body-sm mt-space-sm ${error ? 'text-error' : 'text-on-surface-variant'}`}>
          {error ? t.errorFicha : t.fichaVacia}
        </p>
      </aside>
    );
  }
  return (
    <aside aria-label={t.ficha} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-sm">
      <div className="flex flex-col gap-1">
        <h2 className="font-title-lg text-title-lg text-primary" data-expediente>
          {r.numero_expediente}
        </h2>
        <span className="flex flex-wrap gap-space-xs">
          <Insignia tono={r.tipo === 'reclamo' ? 'aviso' : 'info'}>{t.tipos[r.tipo]}</Insignia>
          <Insignia tono={TONOS[r.estado]}>{t.estados[r.estado]}</Insignia>
          <Insignia tono={TONOS_PLAZO[r.plazo]}>{t.plazos[r.plazo]}</Insignia>
        </span>
      </div>
      <dl className="grid grid-cols-1 gap-space-xs font-body-sm text-body-sm">
        <Dato k={t.recibido} v={fechaHora(r.created_at)} />
        <Dato k={t.vence} v={fechaDia(r.vence_en)} />
        <Dato k={t.respondido} v={r.respondido_en ? fechaHora(r.respondido_en) : null} />
        <Dato k={t.columnas.reclamante} v={r.nombre_reclamante} />
        <Dato k={t.documento} v={`${r.tipo_doc} ${r.numero_doc}`} />
        <Dato k={t.contacto} v={[r.email, r.telefono].filter(Boolean).join(' · ')} />
        <Dato k={t.direccion} v={r.direccion} />
        <Dato k={t.menor} v={r.menor_edad ? [r.apoderado_nombre, r.apoderado_doc].filter(Boolean).join(' · ') : null} />
        <Dato k={t.servicio} v={r.producto_descripcion} />
        <Dato k={t.monto} v={r.monto_reclamado ? `S/ ${r.monto_reclamado}` : null} />
        <Dato k={t.hechos} v={r.descripcion} />
        <Dato k={t.pedido} v={r.pedido} />
      </dl>
      <div className="flex flex-col gap-1">
        <span className="font-label-sm text-label-sm text-secondary uppercase tracking-wider">{t.adjuntos}</span>
        {r.adjuntos.length === 0 ? (
          <span className="font-body-sm text-body-sm text-on-surface-variant">{t.sinAdjuntos}</span>
        ) : (
          <ul className="flex flex-col gap-1">
            {r.adjuntos.map((a) => (
              <li key={a.indice}>
                <a
                  href={`/cms/reclamaciones/adjunto?id=${r.id}&n=${a.indice}`}
                  aria-label={t.descargar(a.nombre)}
                  className="inline-flex items-center gap-1 text-primary font-label-md text-label-md underline break-all"
                >
                  <Icono nombre="attach_file" tamano="sm" />
                  {a.nombre} <span className="text-on-surface-variant no-underline">({tamano(a.bytes)})</span>
                </a>
              </li>
            ))}
          </ul>
        )}
      </div>
      <GestionReclamo
        key={`${r.id}-${r.updated_at}`}
        id={r.id}
        estado={r.estado}
        siguientes={r.siguientes}
        respuesta={r.respuesta_cliente}
        respondido={r.respondido_en !== null}
        notas={r.notas_internas}
      />
      <section aria-label={t.historial} className="flex flex-col gap-1 pt-space-sm border-t border-surface-container">
        <h3 className="font-title-md text-title-md text-primary">{t.historial}</h3>
        {r.historial.length === 0 ? (
          <p className="font-body-sm text-body-sm text-on-surface-variant">{t.sinHistorial}</p>
        ) : (
          <ol className="flex flex-col gap-1 font-label-md text-label-md">
            {r.historial.map((h, i) => (
              <li key={i} className="flex flex-col">
                <span className="text-on-surface">{h.detalle.replace(/^Libro de Reclamaciones · [^:]+: /, '')}</span>
                <span className="text-on-surface-variant">
                  {fechaHora(h.created_at)}
                  {h.usuario ? ` · ${h.usuario}` : ''}
                </span>
              </li>
            ))}
          </ol>
        )}
      </section>
    </aside>
  );
}

export default async function PaginaReclamaciones({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const sp = await searchParams;
  const f: FiltrosReclamos = { estado: sp.estado, tipo: sp.tipo, plazo: sp.plazo, q: sp.q, pagina: sp.pagina };
  const datos = await leerReclamaciones(f);

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

  const idElegido = Number(sp.id ?? datos.data[0]?.id ?? 0);
  const ficha = idElegido > 0 ? await leerReclamo(idElegido) : null;
  const activo = f.estado ?? 'todos';
  const desde = datos.total === 0 ? 0 : (datos.pagina - 1) * datos.por_pagina + 1;
  const hasta = Math.min(datos.pagina * datos.por_pagina, datos.total);
  const sinPagina = { ...f, pagina: undefined };

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <header className="flex flex-col gap-1">
        <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
        <p className="font-body-md text-body-md text-on-surface-variant">{t.descripcion}</p>
      </header>

      <Indicadores i={datos.indicadores} f={f} />

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
          {t.tipo}
          <select name="tipo" defaultValue={f.tipo ?? ''} className="h-10 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface">
            <option value="">{t.todosTipos}</option>
            <option value="queja">{t.tipos.queja}</option>
            <option value="reclamo">{t.tipos.reclamo}</option>
          </select>
        </label>
        <label className="flex flex-col gap-1 font-label-md text-label-md text-secondary">
          {t.plazo}
          <select name="plazo" defaultValue={f.plazo ?? ''} className="h-10 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface">
            <option value="">{t.todosPlazos}</option>
            <option value="vencidos">{t.soloVencidos}</option>
            <option value="por_vencer">{t.soloPorVencer}</option>
          </select>
        </label>
        <div className="flex gap-space-xs">
          <button type="submit" className="h-10 px-space-md rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg">
            {t.aplicar}
          </button>
          <Link href="/cms/reclamaciones" className="h-10 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg inline-flex items-center">
            {t.limpiar}
          </Link>
        </div>
      </form>

      <div className="grid grid-cols-1 xl:grid-cols-[1fr_24rem] gap-space-md items-start">
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
                    datos.data.map((r) => (
                      <tr key={r.id} data-reclamo={r.id} aria-selected={ficha?.id === r.id} className={`h-fila-tabla ${ficha?.id === r.id ? 'bg-surface-container-low' : 'hover:bg-surface-container-low'}`}>
                        <td className="px-space-md py-space-xs whitespace-nowrap">
                          <span className="block font-semibold">{r.numero_expediente}</span>
                          <span className="block font-label-sm text-label-sm text-secondary">
                            {t.tipos[r.tipo]} · {fechaDia(r.created_at)}
                          </span>
                        </td>
                        <td className="px-space-md py-space-xs">
                          <span className="block">{r.nombre_reclamante}</span>
                          <span className="block font-label-sm text-label-sm text-secondary break-all">{r.email}</span>
                        </td>
                        <td className="px-space-md py-space-xs max-w-[16rem]">
                          <span className="block truncate">{r.producto_descripcion}</span>
                        </td>
                        <td className="px-space-md">
                          <Insignia tono={TONOS[r.estado]}>{t.estados[r.estado]}</Insignia>
                        </td>
                        <td className="px-space-md whitespace-nowrap">
                          <span className="block">{fechaDia(r.vence_en)}</span>
                          <span className="block mt-1">
                            <Insignia tono={TONOS_PLAZO[r.plazo]}>{t.plazos[r.plazo]}</Insignia>
                          </span>
                        </td>
                        <td className="px-space-md">
                          <Link href={url(f, { id: String(r.id) })} scroll={false} aria-label={t.verFicha(r.numero_expediente)} className="w-8 h-8 rounded-lg hover:bg-surface-container flex items-center justify-center text-primary">
                            <Icono nombre="more_vert" tamano="sm" />
                          </Link>
                        </td>
                      </tr>
                    ))
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
        <Ficha r={ficha} error={idElegido > 0 && ficha === null} />
      </div>
    </div>
  );
}
