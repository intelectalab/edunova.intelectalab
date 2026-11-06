'use client';

import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react';
import { publicarSecciones, type EstadoPublicacion } from '@/app/cms/editor-acciones';
import { Boton, Icono, useToast } from '@/componentes/base';
import { cms } from '@/contenido/provisional';

/*
 * EDUN-233 · Flujo editorial común a todas las pantallas del CMS (TRASPASO §6):
 * 1. Al editar un campo, la franja pasa a «Cambios sin publicar».
 * 2. «Guardar borrador» guarda sin cambiar lo que ve el sitio.
 * 3. «Publicar» guarda lo pendiente y publica (versión, auditoría y revalidación en la API).
 * Cada pantalla de edición se registra con las secciones que publica y cómo se guarda.
 */

export type PantallaEditable = { claves: string[]; guardar: () => Promise<boolean> };

type Editor = {
  estado: EstadoPublicacion | null;
  sucio: boolean;
  trabajando: boolean;
  hayPantalla: boolean;
  marcarSucio: () => void;
  registrar: (p: PantallaEditable | null) => void;
  guardar: () => Promise<boolean>;
  publicar: () => Promise<void>;
};

const Contexto = createContext<Editor | null>(null);

export function useEditorCms(): Editor {
  const e = useContext(Contexto);
  if (!e) throw new Error('useEditorCms necesita ProveedorEditorCms');
  return e;
}

export function ProveedorEditorCms({ estadoInicial, children }: { estadoInicial: EstadoPublicacion | null; children: ReactNode }) {
  const [estado, setEstado] = useState(estadoInicial);
  const [sucio, setSucio] = useState(false);
  const [trabajando, setTrabajando] = useState(false);
  const [hayPantalla, setHayPantalla] = useState(false);
  const pantalla = useRef<PantallaEditable | null>(null);
  const toast = useToast();

  const registrar = useCallback((p: PantallaEditable | null) => {
    pantalla.current = p;
    setHayPantalla(p !== null);
    setSucio(false);
  }, []);
  const marcarSucio = useCallback(() => setSucio(true), []);

  const guardar = useCallback(async (): Promise<boolean> => {
    const p = pantalla.current;
    if (!p) return false;
    setTrabajando(true);
    try {
      const ok = await p.guardar();
      if (ok) {
        setSucio(false);
        setEstado((e) => ({ ultima_publicacion: e?.ultima_publicacion ?? null, pendientes: Array.from(new Set([...(e?.pendientes ?? []), ...p.claves])) }));
        toast(cms.editor.borradorGuardado);
      }
      return ok;
    } finally {
      setTrabajando(false);
    }
  }, [toast]);

  const publicar = useCallback(async () => {
    const p = pantalla.current;
    if (!p) return;
    if (sucio && !(await guardar())) return;
    setTrabajando(true);
    try {
      const r = await publicarSecciones(p.claves);
      if (r.ok) {
        setEstado({ ultima_publicacion: r.datos.ultima_publicacion, pendientes: r.datos.pendientes });
        toast(r.datos.publicadas.length ? cms.editor.publicado : cms.editor.sinCambios);
      } else {
        toast(r.mensaje);
      }
    } finally {
      setTrabajando(false);
    }
  }, [sucio, guardar, toast]);

  const valor = useMemo<Editor>(
    () => ({ estado, sucio, trabajando, hayPantalla, marcarSucio, registrar, guardar, publicar }),
    [estado, sucio, trabajando, hayPantalla, marcarSucio, registrar, guardar, publicar],
  );

  return <Contexto.Provider value={valor}>{children}</Contexto.Provider>;
}

/** «Guardar borrador» y «Publicar» de la barra superior. */
export function BotonesEditor() {
  const { estado, sucio, trabajando, hayPantalla, guardar, publicar } = useEditorCms();
  const puedeGuardar = hayPantalla && sucio && !trabajando;
  const puedePublicar = hayPantalla && !trabajando && (sucio || (estado?.pendientes.length ?? 0) > 0);
  return (
    <>
      <Boton variante="secundario" icono="save" disabled={!puedeGuardar} onClick={() => void guardar()} title={puedeGuardar ? undefined : cms.barra.sinCambios}>
        {cms.barra.guardar}
      </Boton>
      <Boton variante="accion" icono="rocket_launch" disabled={!puedePublicar} onClick={() => void publicar()} title={puedePublicar ? undefined : cms.barra.sinCambios}>
        {cms.barra.publicar}
      </Boton>
    </>
  );
}

function formatoLima(iso: string): string {
  return new Intl.DateTimeFormat('es-PE', {
    timeZone: 'America/Lima',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(iso));
}

/** Franja de estado: «Cambios sin publicar» o «Última publicación: … por …». */
export function FranjaEstado() {
  const { estado, sucio } = useEditorCms();
  const pendiente = sucio || (estado?.pendientes.length ?? 0) > 0;
  const ultima = estado?.ultima_publicacion;
  return (
    <p data-franja-estado className="px-margin-mobile md:px-margin pt-space-md flex flex-wrap items-center gap-space-xs font-label-md text-label-md text-on-surface-variant">
      {pendiente ? (
        <>
          <Icono nombre="edit_note" tamano="sm" className="text-aviso" />
          <strong className="text-on-surface">{cms.editor.cambiosSinPublicar}</strong>
        </>
      ) : ultima ? (
        <>
          <Icono nombre="history" tamano="sm" className="text-secondary" />
          <span>{cms.editor.ultimaPublicacion}</span>
          <strong className="text-on-surface">{formatoLima(ultima.en)}</strong>
          {ultima.por && (
            <span>
              {cms.editor.por} {ultima.por}
            </span>
          )}
        </>
      ) : (
        <span>{cms.editor.sinPublicaciones}</span>
      )}
    </p>
  );
}
