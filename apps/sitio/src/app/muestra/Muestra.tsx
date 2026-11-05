'use client';

import { useState, type ReactNode } from 'react';
import {
  Boton,
  Chip,
  Insignia,
  Interruptor,
  LineaDeTiempo,
  Modal,
  Tabla,
  TarjetaSeccion,
  useToast,
} from '@/componentes/base';
import { muestra as t } from '@/contenido/provisional';

type Fila = (typeof t.tabla.filas)[number];

function Bloque({ titulo, children }: { titulo: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-space-sm">
      <h2 className="font-title-lg text-title-lg text-primary">{titulo}</h2>
      <div className="flex flex-wrap items-center gap-space-sm">{children}</div>
    </section>
  );
}

export function Muestra() {
  const [visible, setVisible] = useState(true);
  const [modal, setModal] = useState(false);
  const toast = useToast();

  return (
    <main className="max-w-5xl mx-auto px-margin-mobile md:px-margin py-space-xl flex flex-col gap-space-xl">
      <header className="flex flex-col gap-space-xs">
        <h1 className="font-headline-lg-mobile text-headline-lg-mobile md:font-headline-lg md:text-headline-lg text-primary">{t.titulo}</h1>
        <p className="font-body-md text-body-md text-on-surface-variant">{t.descripcion}</p>
      </header>

      <Bloque titulo={t.botones.titulo}>
        <Boton variante="accion" icono="rocket_launch">{t.botones.accion}</Boton>
        <Boton variante="institucional" icono="calendar_month">{t.botones.institucional}</Boton>
        <Boton variante="secundario" icono="save">{t.botones.secundario}</Boton>
        <Boton variante="fantasma">{t.botones.fantasma}</Boton>
      </Bloque>

      <Bloque titulo={t.interruptor.titulo}>
        <Interruptor etiqueta={t.interruptor.etiqueta} activo={visible} onCambio={setVisible} />
      </Bloque>

      <Bloque titulo={t.chips.titulo}>
        {t.chips.lista.map((c) => (
          <Chip key={c.href} {...c} />
        ))}
      </Bloque>

      <Bloque titulo={t.insignias.titulo}>
        <Insignia tono="exito" icono="check_circle">{t.insignias.exito}</Insignia>
        <Insignia tono="aviso" icono="edit_note">{t.insignias.aviso}</Insignia>
        <Insignia tono="error">{t.insignias.error}</Insignia>
        <Insignia tono="info">{t.insignias.info}</Insignia>
        <Insignia tono="neutro">{t.insignias.neutro}</Insignia>
      </Bloque>

      <TarjetaSeccion
        icono={t.tarjeta.icono}
        titulo={t.tarjeta.titulo}
        descripcion={t.tarjeta.descripcion}
        insignia={<Insignia tono="exito">{t.insignias.exito}</Insignia>}
      />

      <section className="flex flex-col gap-space-sm">
        <h2 className="font-title-lg text-title-lg text-primary">{t.tabla.titulo}</h2>
        <Tabla<Fila>
          titulo={t.tabla.titulo}
          textoVacio={t.tabla.vacio}
          filas={t.tabla.filas}
          claveFila={(f) => f.id}
          columnas={[
            { clave: 'autor', titulo: t.tabla.columnas.autor, celda: (f) => f.autor },
            { clave: 'calificacion', titulo: t.tabla.columnas.calificacion, celda: (f) => f.calificacion },
            {
              clave: 'estado',
              titulo: t.tabla.columnas.estado,
              alinear: 'derecha',
              celda: (f) => <Insignia tono={f.estado === t.insignias.exito ? 'exito' : 'neutro'}>{f.estado}</Insignia>,
            },
          ]}
        />
      </section>

      <section className="flex flex-col gap-space-sm">
        <h2 className="font-title-lg text-title-lg text-primary">{t.linea.titulo}</h2>
        <LineaDeTiempo eventos={t.linea.eventos} />
      </section>

      <Bloque titulo={t.modal.titulo}>
        <Boton variante="institucional" onClick={() => setModal(true)}>{t.modal.abrir}</Boton>
        <Modal abierto={modal} onCerrar={() => setModal(false)} titulo={t.modal.tituloModal} etiquetaCerrar={t.modal.cerrar}>
          <p className="font-body-md text-body-md text-on-surface-variant">{t.modal.cuerpo}</p>
        </Modal>
      </Bloque>

      <Bloque titulo={t.toast.titulo}>
        <Boton variante="secundario" onClick={() => toast(t.toast.texto)}>{t.toast.mostrar}</Boton>
      </Bloque>
    </main>
  );
}
