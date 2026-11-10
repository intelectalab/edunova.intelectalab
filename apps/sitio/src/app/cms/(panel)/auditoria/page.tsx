import Link from 'next/link';
import { Icono, Insignia, type TonoInsignia } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import { leerAuditoria, type FiltrosAuditoria, type RegistroAuditoria } from '../../panel-acciones';
import { Restaurar } from './Restaurar';

// EDUN-342 / SIT-061 · Auditoría de cambios y restauración (prototipo #cms-auditoria).
// Filtros, página y registro elegido viajan en la URL. La auditoría es de solo inserción.
export const dynamic = 'force-dynamic';

const t = cms.auditoria;
const TONOS: Record<string, TonoInsignia> = { publicado: 'exito', borrador: 'info', restaurado: 'aviso', editado: 'neutro', ocultado: 'neutro', eliminado: 'error' };
const fecha = (iso: string) =>
  new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));

function url(f: FiltrosAuditoria & { id?: string }, cambios: Partial<FiltrosAuditoria & { id: string }>, base = '/cms/auditoria') {
  const p = new URLSearchParams(Object.entries({ ...f, ...cambios }).filter((e): e is [string, string] => typeof e[1] === 'string' && e[1] !== ''));
  const q = p.toString();
  return `${base}${q ? `?${q}` : ''}`;
}

/** Aplana un valor JSON en rutas legibles («lista.2.precio_mensual» → valor). */
function aplanar(v: unknown, prefijo = '', salida: Record<string, string> = {}): Record<string, string> {
  if (v !== null && typeof v === 'object') {
    for (const [k, x] of Object.entries(v as Record<string, unknown>)) aplanar(x, prefijo ? `${prefijo}.${k}` : k, salida);
    if (Object.keys(v as object).length === 0 && prefijo) salida[prefijo] = Array.isArray(v) ? '[]' : '{}';
  } else if (prefijo || v !== undefined) {
    salida[prefijo || '·'] = v === null || v === undefined ? '' : String(v);
  }
  return salida;
}

function Diferencias({ r }: { r: RegistroAuditoria }) {
  const antes = aplanar(r.valor_antes);
  const despues = aplanar(r.valor_despues);
  const campos = [...new Set([...Object.keys(antes), ...Object.keys(despues)])].filter((k) => antes[k] !== despues[k]);
  if (campos.length === 0) return <p className="font-body-sm text-body-sm text-on-surface-variant">{t.sinDiferencias}</p>;
  return (
    <div className="flex flex-col gap-space-xs">
      <p className="font-label-md text-label-md text-secondary">{t.diferencias(campos.length)}</p>
      <dl className="flex flex-col gap-space-xs max-h-96 overflow-y-auto">
        {campos.slice(0, 60).map((k) => (
          <div key={k} className="p-space-sm rounded-lg bg-surface-container-low flex flex-col gap-1 font-body-sm text-body-sm">
            <dt className="font-label-sm text-label-sm text-secondary break-all">{k}</dt>
            <dd className="flex flex-col gap-1">
              <span className="text-error line-through decoration-error/60 break-words">
                <span className="sr-only">{t.antes}: </span>
                {antes[k] === undefined || antes[k] === '' ? t.sinValor : antes[k].slice(0, 400)}
              </span>
              <span className="text-on-surface break-words">
                <span className="sr-only">{t.despues}: </span>
                {despues[k] === undefined || despues[k] === '' ? t.sinValor : despues[k].slice(0, 400)}
              </span>
            </dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

export default async function AuditoriaCms({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const sp = await searchParams;
  const f: FiltrosAuditoria = { q: sp.q, usuario: sp.usuario, seccion: sp.seccion, accion: sp.accion, pagina: sp.pagina };
  const datos = await leerAuditoria(f);

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

  const elegido = datos.data.find((r) => String(r.id) === sp.id) ?? datos.data[0];
  const desde = datos.total === 0 ? 0 : (datos.pagina - 1) * datos.por_pagina + 1;
  const hasta = Math.min(datos.pagina * datos.por_pagina, datos.total);
  const i = datos.indicadores;
  const indicadores = [
    { titulo: t.indicadores.hoy, valor: i.cambios_hoy, icono: 'today' },
    { titulo: t.indicadores.publicaciones, valor: i.publicaciones_7d, icono: 'publish' },
    { titulo: t.indicadores.usuarios, valor: i.usuarios_con_acceso, icono: 'admin_panel_settings' },
    { titulo: t.indicadores.versiones, valor: i.versiones, icono: 'history' },
  ];
  const sinPagina = { ...f, pagina: undefined };
  const select = 'h-10 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface';

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <header className="flex flex-wrap items-end justify-between gap-space-sm">
        <div className="flex flex-col gap-1">
          <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
          <p className="font-body-md text-body-md text-on-surface-variant">{t.descripcion}</p>
        </div>
        <a href={url(sinPagina, {}, '/cms/auditoria/exportar')} className="inline-flex items-center gap-1 h-10 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg">
          <Icono nombre="download" tamano="sm" />
          {t.exportar}
        </a>
      </header>

      <ul className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-space-md">
        {indicadores.map((c) => (
          <li key={c.titulo} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-1">
            <span className="flex items-center justify-between font-label-md text-label-md text-secondary">
              {c.titulo}
              <Icono nombre={c.icono} className="text-primary" />
            </span>
            <span className="font-headline-md text-headline-md text-primary font-bold">{c.valor}</span>
          </li>
        ))}
      </ul>

      <form method="get" role="search" aria-label={t.filtros} className="grid grid-cols-1 md:grid-cols-[1fr_auto_auto_auto_auto] md:items-end gap-space-sm">
        <label className="flex flex-col gap-1 font-label-md text-label-md text-secondary">
          {t.buscar}
          <input name="q" defaultValue={f.q} placeholder={t.buscarEjemplo} className="h-10 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 font-body-md text-body-md text-on-surface" />
        </label>
        <label className="flex flex-col gap-1 font-label-md text-label-md text-secondary">
          {t.usuario}
          <select name="usuario" defaultValue={f.usuario ?? ''} className={select}>
            <option value="">{t.todosUsuarios}</option>
            {datos.usuarios.map((u) => (
              <option key={u.id} value={u.id}>
                {u.nombre ?? `#${u.id}`}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1 font-label-md text-label-md text-secondary">
          {t.seccion}
          <select name="seccion" defaultValue={f.seccion ?? ''} className={select}>
            <option value="">{t.todasSecciones}</option>
            {Object.entries(datos.secciones).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1 font-label-md text-label-md text-secondary">
          {t.accion}
          <select name="accion" defaultValue={f.accion ?? ''} className={select}>
            <option value="">{t.todasAcciones}</option>
            {Object.entries(datos.acciones).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </label>
        <div className="flex gap-space-xs">
          <button type="submit" className="h-10 px-space-md rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg">
            {t.aplicar}
          </button>
          <Link href="/cms/auditoria" className="h-10 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg inline-flex items-center">
            {t.limpiar}
          </Link>
        </div>
      </form>

      <div className="grid grid-cols-1 xl:grid-cols-[1fr_26rem] gap-space-md items-start">
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
                      <tr key={r.id} data-auditoria={r.id} aria-selected={elegido?.id === r.id} className={`h-fila-tabla ${elegido?.id === r.id ? 'bg-surface-container-low' : 'hover:bg-surface-container-low'}`}>
                        <td className="px-space-md whitespace-nowrap">{fecha(r.created_at)}</td>
                        <td className="px-space-md">
                          <span className="block">{r.usuario ?? t.sistema}</span>
                          <span className="block font-label-sm text-label-sm text-secondary">{cms.nombresRol[r.usuario_rol] ?? r.usuario_rol}</span>
                        </td>
                        <td className="px-space-md whitespace-nowrap">{datos.secciones[r.seccion] ?? r.seccion}</td>
                        <td className="px-space-md">
                          <Insignia tono={TONOS[r.accion] ?? 'neutro'}>{datos.acciones[r.accion] ?? r.accion}</Insignia>
                        </td>
                        <td className="px-space-md max-w-[22rem]">
                          <span className="block truncate">{r.detalle}</span>
                        </td>
                        <td className="px-space-md">
                          <Link href={url(f, { id: String(r.id) })} scroll={false} aria-label={t.verDetalle(r.id)} className="w-8 h-8 rounded-lg hover:bg-surface-container flex items-center justify-center text-primary">
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
            <span data-total-auditoria={datos.total}>{t.mostrando(desde, hasta, datos.total)}</span>
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

        <aside aria-label={t.detalle} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-sm">
          <h2 className="font-title-lg text-title-lg text-primary">{t.detalle}</h2>
          {elegido ? (
            <>
              <p className="font-body-sm text-body-sm text-on-surface">{elegido.detalle}</p>
              <p className="font-label-md text-label-md text-secondary">
                {fecha(elegido.created_at)} · {elegido.usuario ?? t.sistema}
              </p>
              <Diferencias r={elegido} />
              {elegido.restaurable ? <Restaurar key={elegido.id} id={elegido.id} /> : <p className="font-label-md text-label-md text-on-surface-variant">{t.noRestaurable}</p>}
            </>
          ) : (
            <p className="font-body-sm text-body-sm text-on-surface-variant">{t.vacio}</p>
          )}
        </aside>
      </div>
    </div>
  );
}
