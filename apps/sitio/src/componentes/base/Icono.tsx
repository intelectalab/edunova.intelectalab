type Tamano = 'sm' | 'md' | 'lg';

const TAMANOS: Record<Tamano, string> = {
  sm: 'text-icono-sm',
  md: 'text-icono-md',
  lg: 'text-icono-lg',
};

/** Ícono de Material Symbols Outlined. Es decorativo: el texto accesible va en el control. */
export function Icono({ nombre, tamano = 'md', className = '' }: { nombre: string; tamano?: Tamano; className?: string }) {
  return (
    <span aria-hidden="true" className={`material-symbols-outlined ${TAMANOS[tamano]} ${className}`.trim()}>
      {nombre}
    </span>
  );
}
