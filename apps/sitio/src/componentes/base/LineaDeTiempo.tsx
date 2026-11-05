export type EventoLinea = {
  id: string;
  titulo: string;
  fecha: string;
  detalle?: string;
  /** El más reciente se marca en verde, el resto en navy (como el historial del prototipo). */
  destacado?: boolean;
};

/** Línea de tiempo (historial de versiones y de cambios). */
export function LineaDeTiempo({ eventos }: { eventos: EventoLinea[] }) {
  return (
    <ol className="flex flex-col gap-space-md pl-space-md border-l-2 border-surface-container ml-1">
      {eventos.map((e) => (
        <li key={e.id} className="flex flex-col gap-0.5 relative">
          <span
            aria-hidden="true"
            className={`absolute -left-[21px] top-1 w-2.5 h-2.5 rounded-full ${e.destacado ? 'bg-tertiary-container' : 'bg-primary'}`}
          />
          <div className="flex items-center justify-between gap-space-sm">
            <span className="font-label-md text-label-md text-on-surface font-bold">{e.titulo}</span>
            <span className="font-label-sm text-label-sm text-secondary whitespace-nowrap">{e.fecha}</span>
          </div>
          {e.detalle && <p className="font-body-sm text-body-sm text-on-surface-variant">{e.detalle}</p>}
        </li>
      ))}
    </ol>
  );
}
