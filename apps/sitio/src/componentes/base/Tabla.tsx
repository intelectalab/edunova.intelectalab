import type { ReactNode } from 'react';

export type Columna<T> = {
  clave: string;
  titulo: string;
  alinear?: 'izquierda' | 'derecha';
  celda: (fila: T) => ReactNode;
};

type Props<T> = {
  /** Título accesible de la tabla. */
  titulo: string;
  columnas: Columna<T>[];
  filas: T[];
  claveFila: (fila: T) => string;
  /** Texto del estado vacío (sale del CMS). */
  textoVacio: string;
};

/** Tabla de datos del CMS: cabecera surface-container-low y filas de 52 px. */
export function Tabla<T>({ titulo, columnas, filas, claveFila, textoVacio }: Props<T>) {
  const derecha = (c: Columna<T>) => (c.alinear === 'derecha' ? 'text-right' : 'text-left');
  return (
    <div className="rounded-xl overflow-hidden border border-surface-container bg-surface-container-lowest">
      <div className="overflow-x-auto">
        <table className="w-full border-collapse">
          <caption className="sr-only">{titulo}</caption>
          <thead>
            <tr className="bg-surface-container-low text-secondary font-label-md text-label-md uppercase tracking-wider h-11">
              {columnas.map((c) => (
                <th key={c.clave} scope="col" className={`py-3 px-space-md font-semibold ${derecha(c)}`}>
                  {c.titulo}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-surface-container font-body-sm text-body-sm text-on-surface">
            {filas.length === 0 ? (
              <tr className="h-fila-tabla">
                <td colSpan={columnas.length} className="px-space-md text-center text-on-surface-variant">
                  {textoVacio}
                </td>
              </tr>
            ) : (
              filas.map((f) => (
                <tr key={claveFila(f)} className="h-fila-tabla hover:bg-surface-container-low transition-colors">
                  {columnas.map((c) => (
                    <td key={c.clave} className={`px-space-md ${derecha(c)}`}>
                      {c.celda(f)}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
