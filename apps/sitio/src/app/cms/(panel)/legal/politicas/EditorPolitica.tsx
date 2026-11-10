'use client';

import { useRouter } from 'next/navigation';
import { useId, useRef, useState, useTransition } from 'react';
import { Icono, Insignia, useToast, type TonoInsignia } from '@/componentes/base';
import { Campo, ErrorLista } from '@/componentes/cms/Formulario';
import { cms } from '@/contenido/provisional';
import { publicarPolitica, type PoliticaCms } from '../../../panel-acciones';

const TONOS: Record<string, TonoInsignia> = { vigente: 'exito', programada: 'info', anterior: 'neutro' };
const fechaDia = (d: string) => d.slice(0, 10).split('-').reverse().join('/');
const hoyLima = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima' }).format(new Date());

/** Propone la siguiente versión menor: 1.0 → 1.1. */
function siguienteVersion(v: string | undefined): string {
  if (!v) return '1.0';
  const partes = v.split('.').map((n) => Number(n) || 0);
  partes[partes.length - 1] += 1;
  return partes.join('.');
}

/**
 * Editor de texto enriquecido mínimo (negrita, cursiva, títulos, listas y enlaces). El HTML se
 * limpia en la API (solo etiquetas de texto y enlaces https/mailto) antes de guardarse.
 */
function TextoEnriquecido({ html, onCambio, etiqueta, ayuda }: { html: string; onCambio: (h: string) => void; etiqueta: string; ayuda: string }) {
  const t = cms.politicasCms;
  const caja = useRef<HTMLDivElement>(null);
  const idEtiqueta = useId();
  // El HTML inicial se pinta una sola vez: reinyectarlo en cada tecla movería el cursor.
  const [inicial] = useState(html);
  const formato = (comando: string, valor?: string) => {
    caja.current?.focus();
    document.execCommand(comando, false, valor);
    onCambio(caja.current?.innerHTML ?? '');
  };
  const boton = 'h-9 min-w-9 px-2 rounded-lg flex items-center justify-center text-primary hover:bg-surface-container';
  return (
    <div className="flex flex-col gap-space-xs">
      <span id={idEtiqueta} className="font-label-lg text-label-lg text-on-surface">
        {etiqueta}
      </span>
      <div role="toolbar" aria-label={t.barra} className="flex flex-wrap gap-1 p-1 rounded-t-lg border border-b-0 border-outline-variant bg-surface-container-low">
        <button type="button" className={boton} aria-label={t.formatos.negrita} onClick={() => formato('bold')}>
          <Icono nombre="format_bold" tamano="sm" />
        </button>
        <button type="button" className={boton} aria-label={t.formatos.cursiva} onClick={() => formato('italic')}>
          <Icono nombre="format_italic" tamano="sm" />
        </button>
        <button type="button" className={boton} aria-label={t.formatos.titulo} onClick={() => formato('formatBlock', 'h2')}>
          <Icono nombre="title" tamano="sm" />
        </button>
        <button type="button" className={boton} aria-label={t.formatos.lista} onClick={() => formato('insertUnorderedList')}>
          <Icono nombre="format_list_bulleted" tamano="sm" />
        </button>
        <button type="button" className={boton} aria-label={t.formatos.numerada} onClick={() => formato('insertOrderedList')}>
          <Icono nombre="format_list_numbered" tamano="sm" />
        </button>
        <button
          type="button"
          className={boton}
          aria-label={t.formatos.enlace}
          onClick={() => {
            const url = window.prompt(t.enlacePregunta, 'https://');
            if (url && /^(https:\/\/|mailto:|\/)/.test(url)) formato('createLink', url);
          }}
        >
          <Icono nombre="link" tamano="sm" />
        </button>
        <button type="button" className={boton} aria-label={t.formatos.limpiar} onClick={() => formato('removeFormat')}>
          <Icono nombre="format_clear" tamano="sm" />
        </button>
      </div>
      <div
        ref={caja}
        role="textbox"
        aria-multiline="true"
        aria-labelledby={idEtiqueta}
        aria-describedby={`${idEtiqueta}-ayuda`}
        contentEditable
        suppressContentEditableWarning
        onInput={(e) => onCambio((e.target as HTMLDivElement).innerHTML)}
        dangerouslySetInnerHTML={{ __html: inicial }}
        className="politica min-h-[20rem] max-h-[36rem] overflow-y-auto p-space-md rounded-b-lg border border-outline-variant bg-surface-container-lowest font-body-md text-body-md text-on-surface focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary [&_h2]:font-title-lg [&_h2]:text-title-lg [&_h2]:text-primary [&_h2]:mt-space-md [&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-6 [&_ol]:pl-6 [&_a]:text-primary [&_a]:underline [&_p]:my-2"
      />
      <span id={`${idEtiqueta}-ayuda`} className="font-label-sm text-label-sm text-secondary">
        {ayuda}
      </span>
    </div>
  );
}

/**
 * EDUN-395 / SIT-032 · Políticas legales con versiones: historial de las últimas 5, nueva
 * versión con número, vigencia y texto enriquecido, y marca «pendiente de revisión legal».
 * Publicar crea una versión nueva; ninguna versión se edita después (prueba del consentimiento).
 */
export function EditorPolitica({ politica }: { politica: PoliticaCms }) {
  const t = cms.politicasCms;
  const vigente = politica.versiones.find((v) => v.estado === 'vigente');
  const base = vigente ?? politica.versiones[0];
  const [version, setVersion] = useState(siguienteVersion(politica.versiones[0]?.version));
  const [vigenteDesde, setVigenteDesde] = useState(hoyLima());
  const [contenido, setContenido] = useState(base?.contenido ?? '');
  const [semilla, setSemilla] = useState(0);
  const [revision, setRevision] = useState(base?.borrador ?? true);
  const [errores, setErrores] = useState<Record<string, string>>({});
  const [publicando, iniciar] = useTransition();
  const router = useRouter();
  const avisar = useToast();

  function partirDe(html: string) {
    setContenido(html);
    setSemilla((n) => n + 1);
  }

  function publicar() {
    if (!window.confirm(t.confirmar)) return;
    setErrores({});
    iniciar(async () => {
      const r = await publicarPolitica(politica.tipo, { version, vigente_desde: vigenteDesde, contenido, revision_pendiente: revision });
      if (!r.ok) return setErrores(Object.keys(r.errores).length ? r.errores : { _: r.mensaje });
      avisar(t.publicada);
      router.refresh();
    });
  }

  return (
    <div className="grid gap-space-md xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
      <section aria-labelledby={`nueva-${politica.tipo}`} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-md min-w-0">
        <h2 id={`nueva-${politica.tipo}`} className="font-title-lg text-title-lg text-primary">
          {t.nueva} · {politica.nombre}
        </h2>
        {Object.keys(errores).length > 0 && <ErrorLista error={errores._ ?? Object.values(errores)[0]} />}
        <div className="grid gap-space-md md:grid-cols-2">
          <Campo etiqueta={t.version} max={15} valor={version} error={errores.version} ayuda={t.versionAyuda(vigente?.version ?? '—')} onCambio={setVersion} />
          <Campo etiqueta={t.vigenteDesde} tipo="date" valor={vigenteDesde} error={errores.vigente_desde} onCambio={setVigenteDesde} />
        </div>
        <TextoEnriquecido key={semilla} html={contenido} onCambio={setContenido} etiqueta={t.contenido} ayuda={t.contenidoAyuda} />
        {errores.contenido && <span className="font-label-sm text-label-sm text-error">{errores.contenido}</span>}
        <label className="flex items-start gap-space-sm font-body-sm text-body-sm text-on-surface">
          <input type="checkbox" checked={revision} onChange={(e) => setRevision(e.target.checked)} className="mt-0.5 w-4 h-4 accent-primary" />
          {t.marcarRevision}
        </label>
        <button type="button" onClick={publicar} disabled={publicando} className="self-start h-11 px-space-lg rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg disabled:opacity-60">
          {publicando ? t.publicando : t.publicar}
        </button>
      </section>

      <section aria-labelledby={`historial-${politica.tipo}`} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-sm">
        <h2 id={`historial-${politica.tipo}`} className="font-title-md text-title-md text-primary">
          {t.historial}
        </h2>
        <ol className="flex flex-col gap-space-sm">
          {politica.versiones.map((v) => (
            <li key={v.id} data-version={v.version} className="flex flex-col gap-1 p-space-sm rounded-lg border border-surface-container">
              <span className="flex flex-wrap items-center gap-space-xs">
                <span className="font-label-lg text-label-lg text-on-surface">v{v.version}</span>
                <Insignia tono={TONOS[v.estado]}>{t.estados[v.estado]}</Insignia>
                {v.borrador && <Insignia tono="aviso">{t.revisionPendiente}</Insignia>}
              </span>
              <span className="font-label-sm text-label-sm text-secondary">
                {t.vigenteDesde}: {fechaDia(v.vigente_desde)}
              </span>
              <details>
                <summary className="cursor-pointer font-label-md text-label-md text-primary">{t.ver(v.version)}</summary>
                <div className="mt-space-xs max-h-60 overflow-y-auto font-body-sm text-body-sm text-on-surface [&_h2]:font-label-lg [&_ul]:list-disc [&_ul]:pl-5" dangerouslySetInnerHTML={{ __html: v.contenido }} />
              </details>
              <button type="button" onClick={() => partirDe(v.contenido)} className="self-start font-label-md text-label-md text-primary underline">
                {t.usarComoBase}
              </button>
            </li>
          ))}
        </ol>
      </section>
    </div>
  );
}
