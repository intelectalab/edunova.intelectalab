import Link from 'next/link';
import { Icono } from '@/componentes/base';
import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { demo, paginas } from '@/contenido/provisional';
import { textoFecha } from '@/lib/calendario';
import { obtenerLanding } from '@/lib/landing';
import { metadatosPagina } from '@/lib/seo';
import { EventoConversion } from './EventoConversion';

// EDUN-359 / SIT-064 · /gracias: página de conversión tras pedir la demo (evento demo_request).
// No se indexa y solo muestra la fecha y la hora (ningún dato personal en la URL).
export const dynamic = 'force-dynamic';

const t = paginas.gracias;
export const generateMetadata = () => metadatosPagina(t.titulo, t.texto, '/gracias', false);

export default async function Gracias({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const [datos, sp] = await Promise.all([obtenerLanding(), searchParams]);
  const fecha = /^\d{4}-\d{2}-\d{2}$/.test(sp.fecha ?? '') ? sp.fecha! : null;
  const hora = demo.horas.find((h) => h.valor === sp.hora);
  const plan = demo.planes.find((p) => p.valor === sp.plan)?.valor ?? '';

  return (
    <MarcoPublico datos={datos} comercial>
      <EventoConversion plan={plan} />
      <div className="max-w-2xl mx-auto px-margin-mobile md:px-margin flex flex-col items-center text-center gap-space-md py-space-xl">
        <span className="w-16 h-16 rounded-full bg-accion text-accion-texto flex items-center justify-center shadow-lg">
          <Icono nombre="check" tamano="lg" />
        </span>
        <h1 className="font-headline-lg-mobile text-headline-lg-mobile md:font-headline-lg md:text-headline-lg text-primary">{t.titulo}</h1>
        {fecha && hora && <p className="font-title-md text-title-md text-on-surface">{t.demo(textoFecha(fecha), hora.etiqueta)}</p>}
        <p className="font-body-lg text-body-lg text-secondary">{t.texto}</p>
        <div className="flex flex-wrap justify-center gap-space-sm">
          <Link href="/funcionalidades" className="px-space-md py-space-sm rounded-lg bg-primary text-on-primary font-label-lg text-label-lg">
            {t.funcionalidades}
          </Link>
          <Link href="/preguntas-frecuentes" className="px-space-md py-space-sm rounded-lg bg-surface-container hover:bg-surface-container-high text-primary font-label-lg text-label-lg">
            {t.preguntas}
          </Link>
        </div>
      </div>
    </MarcoPublico>
  );
}
