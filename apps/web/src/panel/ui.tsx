import { useEffect, type ReactNode } from 'react';

// Componentes comunes de la web de gestión (los hooks y formatos están en utilidades.ts).

export function Titulo({ migas, titulo, descripcion, children }: { migas: string; titulo: string; descripcion?: string; children?: ReactNode }) {
  return (
    <div className="titulo">
      <div className="migas">{migas}</div>
      <div className="titulo-fila">
        <h1>{titulo}</h1>
        {children}
      </div>
      {descripcion && <p>{descripcion}</p>}
    </div>
  );
}

export function Aviso({ tipo = 'info', children }: { tipo?: 'info' | 'error' | 'ok' | 'ambar'; children: ReactNode }) {
  if (!children) return null;
  return (
    <div className={`aviso${tipo === 'info' ? '' : ` aviso-${tipo}`}`} role={tipo === 'error' ? 'alert' : 'status'}>
      {children}
    </div>
  );
}

export function Campo({ etiqueta, ayuda, ancho, children }: { etiqueta: string; ayuda?: string; ancho?: boolean; children: ReactNode }) {
  return (
    <label className={`campo${ancho ? ' campo-ancho' : ''}`}>
      <span>{etiqueta}</span>
      {children}
      {ayuda && <small>{ayuda}</small>}
    </label>
  );
}

export function Pestanas<T extends string>({ opciones, actual, alCambiar }: { opciones: [T, string][]; actual: T; alCambiar: (v: T) => void }) {
  return (
    <div className="pestanas" role="tablist">
      {opciones.map(([valor, nombre]) => (
        <button key={valor} type="button" role="tab" aria-selected={actual === valor} onClick={() => alCambiar(valor)}>
          {nombre}
        </button>
      ))}
    </div>
  );
}

export function Dialogo({ titulo, alCerrar, children }: { titulo: string; alCerrar: () => void; children: ReactNode }) {
  useEffect(() => {
    const tecla = (e: KeyboardEvent) => e.key === 'Escape' && alCerrar();
    window.addEventListener('keydown', tecla);
    return () => window.removeEventListener('keydown', tecla);
  }, [alCerrar]);
  return (
    <div className="capa" onMouseDown={(e) => e.target === e.currentTarget && alCerrar()}>
      <div className="dialogo" role="dialog" aria-modal="true" aria-label={titulo}>
        <h2>{titulo}</h2>
        {children}
      </div>
    </div>
  );
}
