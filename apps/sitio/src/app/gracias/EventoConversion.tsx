'use client';

import { useEffect } from 'react';
import { registrarEvento } from '@/lib/analitica';

/** EDUN-359 · Evento de conversión de la página /gracias (una vez por carga). */
export function EventoConversion({ plan }: { plan: string }) {
  useEffect(() => {
    registrarEvento('demo_request', { plan, source: 'demo_largo' });
  }, [plan]);
  return null;
}
