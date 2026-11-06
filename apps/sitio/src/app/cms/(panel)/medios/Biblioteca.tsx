'use client';

import { useState } from 'react';
import type { Medio } from '@/app/cms/medios-acciones';
import { useToast } from '@/componentes/base';
import { SelectorMedios, VistaMedio } from '@/componentes/cms/SelectorMedios';
import { cms } from '@/contenido/provisional';

export function Biblioteca({ inicial }: { inicial: Medio[] }) {
  const [medios, setMedios] = useState(inicial);
  const [elegido, setElegido] = useState<Medio | null>(null);
  const toast = useToast();

  return (
    <div className="flex flex-col gap-space-lg">
      <div className="flex flex-wrap items-start gap-space-lg">
        <SelectorMedios
          etiqueta={cms.medios.abrirSelector}
          onElegir={(m) => {
            setElegido(m);
            setMedios((l) => (l.some((x) => x.id === m.id) ? l : [m, ...l]));
            toast(cms.medios.listo);
          }}
        />
        {elegido && (
          <section aria-label={cms.medios.vistaPrevia} className="w-72 max-w-full">
            <VistaMedio medio={elegido} />
          </section>
        )}
      </div>
      {medios.length === 0 ? (
        <p className="font-body-md text-body-md text-secondary">{cms.medios.vacia}</p>
      ) : (
        <ul aria-label={cms.medios.biblioteca} className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-space-md">
          {medios.map((m) => (
            <li key={m.id}>
              <VistaMedio medio={m} compacta />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
