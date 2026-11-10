import { Campo } from '../panel/ui';
import type { AnioResumen } from './anios';

/** Selector del año escolar que se está viendo. */
export function SelectorAnio({ anios, anio, alCambiar }: { anios: AnioResumen[]; anio: number | null; alCambiar: (id: number) => void }) {
  return (
    <Campo etiqueta="Año escolar">
      <select value={anio ?? ''} onChange={(e) => alCambiar(Number(e.target.value))}>
        {anios.map((a) => (
          <option key={a.id} value={a.id}>
            {a.anio}
            {a.estado === 'abierto' ? ' (abierto)' : a.estado === 'planificado' ? ' (planificado)' : ''}
          </option>
        ))}
      </select>
    </Campo>
  );
}
