'use client';

import { TarjetaSeccion } from '@/componentes/base';
import { AvisoBorrador, Campo, useBorrador } from '@/componentes/cms/Formulario';
import { VistaPrevia } from '@/componentes/cms/VistaPrevia';
import { cms } from '@/contenido/provisional';

export type ContenidoHero = {
  insignia: string;
  titulo: string;
  subtitulo: string;
  cta_principal: string;
  cta_secundario: string | null;
  sellos: string | null;
};

type ClaveHero = keyof ContenidoHero;
const CAMPOS: ClaveHero[] = ['insignia', 'titulo', 'subtitulo', 'cta_principal', 'cta_secundario', 'sellos'];
const OPCIONALES: ClaveHero[] = ['cta_secundario', 'sellos'];

/** El titular de 60+ caracteres ocupa más de tres líneas en un celular de 390 px (prototipo). */
const TITULAR_CORTO = 60;

/**
 * EDUN-233 / EDUN-322 · Hero y Portada: textos del hero y aviso de la barra superior, con el
 * previsualizador en vivo y un resumen. Flujo borrador → publicar de la franja del panel.
 */
export function EditorHero({ hero, barra, conBorrador }: { hero: ContenidoHero; barra: { aviso: string }; conBorrador: boolean }) {
  const t = cms.hero;
  const { valores, cambiar, errores } = useBorrador({ hero, barra_superior: barra });
  const h = valores.hero as ContenidoHero;
  const aviso = (valores.barra_superior as { aviso: string }).aviso;
  const botones = [h.cta_principal, h.cta_secundario].filter(Boolean).length;

  return (
    <div className="grid gap-space-md 2xl:grid-cols-[minmax(0,32rem)_minmax(0,1fr)] items-start">
      <div className="flex flex-col gap-space-md min-w-0">
        <TarjetaSeccion icono="view_carousel" titulo={t.titulo} descripcion={t.descripcion}>
          <AvisoBorrador visible={conBorrador}>{t.borradorPendiente}</AvisoBorrador>
          <form className="grid gap-space-md md:grid-cols-2 2xl:grid-cols-1" onSubmit={(e) => e.preventDefault()}>
            {CAMPOS.map((c) => (
              <div key={c} className={c === 'titulo' || c === 'subtitulo' ? 'md:col-span-2 2xl:col-span-1' : undefined}>
                <Campo
                  etiqueta={t.campos[c].etiqueta}
                  max={t.campos[c].max}
                  filas={c === 'subtitulo' ? 3 : undefined}
                  valor={h[c] ?? ''}
                  error={errores.hero?.[c]}
                  onCambio={(v) => cambiar('hero', (x) => ({ ...x, [c]: v === '' && OPCIONALES.includes(c) ? null : v }))}
                />
              </div>
            ))}
          </form>
          <dl aria-label={t.resumen} className="flex flex-wrap gap-x-space-md gap-y-1 font-label-md text-label-md text-secondary" data-resumen-hero>
            <div>
              <dt className="sr-only">{t.campos.titulo.etiqueta}</dt>
              <dd>{t.resumenTitular(h.titulo.length)}</dd>
            </div>
            <div>
              <dt className="sr-only">{t.campos.cta_principal.etiqueta}</dt>
              <dd>{t.resumenBotones(botones)}</dd>
            </div>
          </dl>
          {h.titulo.length > TITULAR_CORTO && <p className="font-label-md text-label-md text-aviso-texto">{t.resumenTitularLargo}</p>}
        </TarjetaSeccion>

        <TarjetaSeccion icono="campaign" titulo={t.barra} descripcion={t.barraDescripcion}>
          <Campo
            etiqueta={t.aviso.etiqueta}
            max={t.aviso.max}
            valor={aviso}
            error={errores.barra_superior?.aviso}
            onCambio={(v) => cambiar('barra_superior', () => ({ aviso: v }))}
          />
        </TarjetaSeccion>
      </div>

      <div className="min-w-0 2xl:sticky 2xl:top-24">
        <VistaPrevia hero={h} aviso={aviso} />
      </div>
    </div>
  );
}
