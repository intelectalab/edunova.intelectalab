"use client";

import { Icono } from "@/componentes/base";
import { demo as t } from "@/contenido/provisional";
import { mesCalendario, mesInicial, textoFecha } from "@/lib/calendario";

type Mes = { anio: number; mes: number };

type Props = {
  hoy: string;
  vista: Mes;
  setVista: (f: (m: Mes) => Mes) => void;
  fecha: string | null;
  setFecha: (f: string) => void;
  hora: string | null;
  setHora: (h: string) => void;
  invalido: boolean;
};

/**
 * EDUN-267 / EDUN-359 · Calendario mensual de la demo (días hábiles desde mañana, hasta 3 meses)
 * y horas fijas en hora de Lima. Lo usan la ventana «Solicitar demo» y la página /demo.
 */
export function CalendarioDemo({
  hoy,
  vista,
  setVista,
  fecha,
  setFecha,
  hora,
  setHora,
  invalido,
}: Props) {
  const { huecos, dias } = mesCalendario(vista.anio, vista.mes, hoy);
  const inicio = mesInicial(hoy);
  const enInicio = vista.anio === inicio.anio && vista.mes === inicio.mes;
  const tope = vista.anio * 12 + vista.mes >= inicio.anio * 12 + inicio.mes + 2; // hasta 3 meses
  const moverMes = (n: number) =>
    setVista(({ anio, mes }) => ({
      anio: anio + Math.floor((mes + n) / 12),
      mes: (((mes + n) % 12) + 12) % 12,
    }));

  return (
    <>
      <div
        className={`rounded-xl border p-3 ${invalido ? "border-error" : "border-outline-variant"}`}
      >
        <div className="flex items-center justify-between mb-2">
          <button
            type="button"
            onClick={() => moverMes(-1)}
            disabled={enInicio}
            aria-label={t.mesAnterior}
            className="w-8 h-8 rounded-full hover:bg-surface-container flex items-center justify-center text-primary disabled:opacity-35 disabled:cursor-not-allowed"
          >
            <Icono nombre="chevron_left" />
          </button>
          <p
            aria-live="polite"
            className="font-title-md text-title-md text-primary capitalize"
          >
            {t.meses[vista.mes]} {vista.anio}
          </p>
          <button
            type="button"
            onClick={() => moverMes(1)}
            disabled={tope}
            aria-label={t.mesSiguiente}
            className="w-8 h-8 rounded-full hover:bg-surface-container flex items-center justify-center text-primary disabled:opacity-35 disabled:cursor-not-allowed"
          >
            <Icono nombre="chevron_right" />
          </button>
        </div>
        <div
          aria-hidden="true"
          className="grid grid-cols-7 text-center font-label-sm text-label-sm text-secondary uppercase"
        >
          {t.diasSemana.map((d) => (
            <span key={d}>{d}</span>
          ))}
        </div>
        <div
          className="grid grid-cols-7 gap-1 mt-1"
          role="group"
          aria-label={`${t.meses[vista.mes]} ${vista.anio}`}
        >
          {Array.from({ length: huecos }, (_, i) => (
            <span key={`h${i}`} />
          ))}
          {dias.map((d) => {
            const sel = d.fecha === fecha;
            return (
              <button
                key={d.fecha}
                type="button"
                disabled={!d.disponible}
                aria-pressed={sel}
                aria-label={`${textoFecha(d.fecha)}${d.disponible ? "" : `, ${t.noDisponible}`}`}
                data-fecha={d.fecha}
                onClick={() => setFecha(d.fecha)}
                className={`h-9 rounded-lg font-label-lg text-label-lg ${
                  sel
                    ? "bg-primary text-on-primary"
                    : d.disponible
                      ? "text-primary hover:bg-secondary-container"
                      : "text-outline cursor-not-allowed line-through decoration-1"
                }`}
              >
                {d.dia}
              </button>
            );
          })}
        </div>
      </div>
      <div
        className="grid grid-cols-3 gap-2"
        role="group"
        aria-label={t.horasDisponibles}
      >
        {t.horas.map((h) => (
          <button
            key={h.valor}
            type="button"
            aria-pressed={hora === h.valor}
            onClick={() => setHora(h.valor)}
            className={`py-2 rounded-lg font-label-lg text-label-lg ${hora === h.valor ? "bg-primary text-on-primary" : "bg-surface-container text-primary hover:bg-surface-container-high"}`}
          >
            {h.etiqueta}
          </button>
        ))}
      </div>
    </>
  );
}
