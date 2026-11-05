import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
  Boton,
  DURACION_TOAST_MS,
  Insignia,
  Interruptor,
  LineaDeTiempo,
  Modal,
  ProveedorToast,
  Tabla,
  TarjetaSeccion,
  useToast,
} from '.';

describe('Botón', () => {
  it('el botón de acción usa el token único --accion (D-SIT-12)', () => {
    render(<Boton variante="accion">Publicar</Boton>);
    const boton = screen.getByRole('button', { name: 'Publicar' });
    expect(boton).toHaveClass('bg-accion', 'text-accion-texto');
    expect(boton).toHaveAttribute('type', 'button');
  });

  it('cada variante tiene su estilo', () => {
    render(
      <>
        <Boton variante="institucional">A</Boton>
        <Boton variante="secundario">B</Boton>
        <Boton variante="fantasma">C</Boton>
      </>,
    );
    expect(screen.getByRole('button', { name: 'A' })).toHaveClass('bg-primary');
    expect(screen.getByRole('button', { name: 'B' })).toHaveClass('bg-surface-container-low');
    expect(screen.getByRole('button', { name: 'C' })).toHaveClass('bg-transparent');
  });
});

describe('Interruptor', () => {
  function Prueba() {
    const [activo, setActivo] = useState(false);
    return <Interruptor etiqueta="Visible en el sitio" activo={activo} onCambio={setActivo} />;
  }

  it('es un switch accesible que cambia con clic y con teclado', async () => {
    const u = userEvent.setup();
    render(<Prueba />);
    const sw = screen.getByRole('switch', { name: 'Visible en el sitio' });
    expect(sw).not.toBeChecked();
    await u.click(sw);
    expect(sw).toBeChecked();
    await u.keyboard(' ');
    expect(sw).not.toBeChecked();
  });

  it('con la etiqueta oculta sigue teniendo nombre accesible', () => {
    render(<Interruptor etiqueta="Visible" ocultarEtiqueta activo onCambio={() => {}} />);
    expect(screen.getByRole('switch', { name: 'Visible' })).toBeChecked();
  });
});

describe('Modal', () => {
  function Prueba({ onCerrar }: { onCerrar?: () => void }) {
    const [abierto, setAbierto] = useState(false);
    return (
      <>
        <button onClick={() => setAbierto(true)}>Abrir</button>
        <Modal
          abierto={abierto}
          onCerrar={() => {
            onCerrar?.();
            setAbierto(false);
          }}
          titulo="Solicitar demo"
          etiquetaCerrar="Cerrar"
        >
          <input aria-label="Nombre" />
        </Modal>
      </>
    );
  }

  it('abre como diálogo con título, enfoca dentro y Escape lo cierra devolviendo el foco', async () => {
    const u = userEvent.setup();
    render(<Prueba />);
    const abrir = screen.getByRole('button', { name: 'Abrir' });
    await u.click(abrir);
    const dialogo = screen.getByRole('dialog', { name: 'Solicitar demo' });
    expect(dialogo).toHaveAttribute('aria-modal', 'true');
    expect(screen.getByRole('button', { name: 'Cerrar' })).toHaveFocus();
    await u.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(abrir).toHaveFocus();
  });

  it('Tab no sale del modal', async () => {
    const u = userEvent.setup();
    render(<Prueba />);
    await u.click(screen.getByRole('button', { name: 'Abrir' }));
    await u.tab();
    expect(screen.getByRole('textbox', { name: 'Nombre' })).toHaveFocus();
    await u.tab();
    expect(screen.getByRole('button', { name: 'Cerrar' })).toHaveFocus();
  });

  it('el botón de cerrar lo cierra', async () => {
    const u = userEvent.setup();
    const onCerrar = vi.fn();
    render(<Prueba onCerrar={onCerrar} />);
    await u.click(screen.getByRole('button', { name: 'Abrir' }));
    await u.click(screen.getByRole('button', { name: 'Cerrar' }));
    expect(onCerrar).toHaveBeenCalledOnce();
  });
});

describe('Aviso flotante', () => {
  function Disparador() {
    const toast = useToast();
    return <button onClick={() => toast('Borrador guardado')}>Guardar</button>;
  }

  it('se anuncia en una región aria-live y desaparece solo', () => {
    vi.useFakeTimers();
    render(
      <ProveedorToast>
        <Disparador />
      </ProveedorToast>,
    );
    act(() => screen.getByRole('button', { name: 'Guardar' }).click());
    expect(screen.getByRole('status')).toHaveTextContent('Borrador guardado');
    act(() => vi.advanceTimersByTime(DURACION_TOAST_MS));
    expect(screen.getByRole('status')).toBeEmptyDOMElement();
    vi.useRealTimers();
  });
});

describe('Tabla', () => {
  const columnas = [{ clave: 'n', titulo: 'Nombre', celda: (f: { n: string }) => f.n }];

  it('muestra cabecera y filas de 52 px', () => {
    render(<Tabla titulo="Testimonios" columnas={columnas} filas={[{ n: 'Ana' }]} claveFila={(f) => f.n} textoVacio="Vacío" />);
    expect(screen.getByRole('table', { name: 'Testimonios' })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Nombre' })).toBeInTheDocument();
    expect(screen.getByRole('cell', { name: 'Ana' }).closest('tr')).toHaveClass('h-fila-tabla');
  });

  it('muestra el estado vacío', () => {
    render(<Tabla titulo="Testimonios" columnas={columnas} filas={[]} claveFila={(f) => f.n} textoVacio="No hay registros." />);
    expect(screen.getByRole('cell', { name: 'No hay registros.' })).toBeInTheDocument();
  });
});

describe('Tarjeta, insignia y línea de tiempo', () => {
  it('la tarjeta de sección muestra título, descripción e insignia', () => {
    render(<TarjetaSeccion icono="view_carousel" titulo="Hero" descripcion="Portada" insignia={<Insignia tono="exito">Publicado</Insignia>} />);
    expect(screen.getByRole('heading', { name: 'Hero' })).toBeInTheDocument();
    expect(screen.getByText('Portada')).toBeInTheDocument();
    expect(screen.getByText('Publicado')).toHaveClass('bg-tertiary-fixed');
  });

  it('los íconos no se leen como texto', () => {
    render(<Insignia icono="check_circle">Publicado</Insignia>);
    expect(screen.getByText('check_circle')).toHaveAttribute('aria-hidden', 'true');
  });

  it('la línea de tiempo es una lista ordenada con fecha', () => {
    render(<LineaDeTiempo eventos={[{ id: '1', titulo: 'Publicado', fecha: '04/11/2026 10:42' }]} />);
    expect(screen.getByRole('list').tagName).toBe('OL');
    expect(screen.getByText('04/11/2026 10:42')).toBeInTheDocument();
  });
});
