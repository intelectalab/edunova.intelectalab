'use client';

import { useEffect, useId, useRef, useState } from 'react';
import { TarjetaSeccion } from '@/componentes/base';
import { useEditorCms } from '@/componentes/cms/EditorCms';
import { cms } from '@/contenido/provisional';
import { guardarBorrador } from '../../../editor-acciones';

export type ContenidoHero = {
  insignia: string;
  titulo: string;
  subtitulo: string;
  cta_principal: string;
  cta_secundario: string | null;
  sellos: string | null;
};

type Campo = keyof ContenidoHero;
const CAMPOS: Campo[] = ['insignia', 'titulo', 'subtitulo', 'cta_principal', 'cta_secundario', 'sellos'];

const ENTRADA =
  'w-full px-space-md py-space-sm rounded-lg border bg-surface-container-lowest font-body-md text-body-md text-on-surface ' +
  'focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary';

function CampoTexto({ campo, valor, error, onCambio }: { campo: Campo; valor: string; error?: string; onCambio: (v: string) => void }) {
  const id = useId();
  const { etiqueta, max } = cms.hero.campos[campo];
  const largo = campo === 'subtitulo';
  const props = {
    id,
    value: valor,
    maxLength: max,
    'aria-invalid': error ? true : undefined,
    'aria-describedby': `${id}-ayuda`,
    className: `${ENTRADA} ${error ? 'border-error' : 'border-outline-variant'}`,
    onChange: (e: { target: { value: string } }) => onCambio(e.target.value),
  };
  return (
    <div className="flex flex-col gap-space-xs">
      <label htmlFor={id} className="font-label-lg text-label-lg text-on-surface">
        {etiqueta}
      </label>
      {largo ? <textarea rows={3} {...props} /> : <input {...props} />}
      <div id={`${id}-ayuda`} className="flex justify-between font-label-sm text-label-sm">
        <span className="text-error">{error}</span>
        <span className="text-secondary">
          {valor.length}/{max}
        </span>
      </div>
    </div>
  );
}

/** EDUN-233 · Edición de los textos del hero con el flujo borrador → publicar. */
export function EditorHero({ inicial, conBorrador }: { inicial: ContenidoHero; conBorrador: boolean }) {
  const { registrar, marcarSucio } = useEditorCms();
  const [valores, setValores] = useState(inicial);
  const [errores, setErrores] = useState<Partial<Record<Campo, string>>>({});
  const actuales = useRef(valores);
  actuales.current = valores;

  useEffect(() => {
    registrar({
      claves: ['hero'],
      guardar: async () => {
        const r = await guardarBorrador('hero', actuales.current);
        if (r.ok) {
          setErrores({});
          return true;
        }
        setErrores(Object.fromEntries(Object.entries(r.errores ?? {}).map(([k, v]) => [k, v[0]])) as Partial<Record<Campo, string>>);
        return false;
      },
    });
    return () => registrar(null);
  }, [registrar]);

  function cambiar(campo: Campo, v: string) {
    setValores((x) => ({ ...x, [campo]: v === '' && (campo === 'cta_secundario' || campo === 'sellos') ? null : v }));
    marcarSucio();
  }

  return (
    <TarjetaSeccion icono="view_carousel" titulo={cms.hero.titulo} descripcion={cms.hero.descripcion}>
      {conBorrador && <p className="px-space-md py-space-sm rounded-lg bg-aviso-fondo border border-aviso-borde text-aviso-texto font-body-sm text-body-sm">{cms.hero.borradorPendiente}</p>}
      <form className="grid gap-space-md md:grid-cols-2" onSubmit={(e) => e.preventDefault()}>
        {CAMPOS.map((c) => (
          <div key={c} className={c === 'titulo' || c === 'subtitulo' ? 'md:col-span-2' : undefined}>
            <CampoTexto campo={c} valor={valores[c] ?? ''} error={errores[c]} onCambio={(v) => cambiar(c, v)} />
          </div>
        ))}
      </form>
    </TarjetaSeccion>
  );
}
