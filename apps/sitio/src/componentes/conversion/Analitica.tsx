'use client';

import { useEffect } from 'react';
import { canalContacto, registrarEvento, ubicacionDe, umbralesNuevos } from '@/lib/analitica';

/**
 * EDUN-318 / SIT-058 · Eventos de toda la página que no pertenecen a un componente:
 * clics en CTA de demo y en contacto (WhatsApp, teléfono, correo), profundidad de scroll,
 * secciones vistas y preguntas frecuentes abiertas. Un elemento con data-evento registra ese
 * evento al hacer clic (con data-etiqueta opcional).
 */
export function Analitica() {
  useEffect(() => {
    function alHacerClic(e: MouseEvent) {
      const objetivo = e.target as Element | null;
      const propio = objetivo?.closest?.('[data-evento]') as HTMLElement | null;
      if (propio?.dataset.evento) {
        registrarEvento(propio.dataset.evento as Parameters<typeof registrarEvento>[0], { etiqueta: propio.dataset.etiqueta ?? propio.textContent ?? '', ubicacion: ubicacionDe(propio) });
        return;
      }
      const a = objetivo?.closest?.('a[href]') as HTMLAnchorElement | null;
      if (!a) return;
      const href = a.getAttribute('href') ?? '';
      const canal = canalContacto(href);
      if (canal) {
        registrarEvento('contact_click', { canal, ubicacion: ubicacionDe(a) });
        return;
      }
      if (/#demo$/.test(href)) registrarEvento('cta_click', { cta_text: a.textContent ?? '', ubicacion: ubicacionDe(a) });
    }

    const registrados = new Set<number>();
    let pendiente = false;
    function alDesplazar() {
      if (pendiente) return;
      pendiente = true;
      requestAnimationFrame(() => {
        pendiente = false;
        const alto = document.documentElement.scrollHeight - window.innerHeight;
        if (alto <= 0) return;
        for (const u of umbralesNuevos(Math.round((window.scrollY / alto) * 100), registrados)) {
          registrados.add(u);
          registrarEvento('scroll_depth', { porcentaje: u });
        }
      });
    }

    function alAbrirDetalle(e: Event) {
      const d = e.target as HTMLDetailsElement;
      if (d.tagName === 'DETAILS' && d.open) registrarEvento('faq_open', { pregunta: d.querySelector('summary')?.textContent ?? '' });
    }

    const vistas = new Set<string>();
    const observador =
      'IntersectionObserver' in window
        ? new IntersectionObserver(
            (entradas) => {
              for (const en of entradas) {
                if (!en.isIntersecting) continue;
                const seccion = ubicacionDe(en.target);
                if (vistas.has(seccion)) continue;
                vistas.add(seccion);
                registrarEvento('section_view', { seccion });
              }
            },
            { threshold: 0.4 },
          )
        : null;
    document.querySelectorAll('main section').forEach((s) => observador?.observe(s));

    // En captura: antes de que la ventana de demo intercepte el clic.
    document.addEventListener('click', alHacerClic, true);
    document.addEventListener('toggle', alAbrirDetalle, true);
    window.addEventListener('scroll', alDesplazar, { passive: true });
    return () => {
      document.removeEventListener('click', alHacerClic, true);
      document.removeEventListener('toggle', alAbrirDetalle, true);
      window.removeEventListener('scroll', alDesplazar);
      observador?.disconnect();
    };
  }, []);

  return null;
}
