import { metadatosPolitica, PaginaPolitica } from '@/componentes/legal/PaginaPolitica';

// EDUN-287 / SIT-053 · Versión vigente en su propia página (SEO).
export const dynamic = 'force-dynamic';

export const generateMetadata = () => metadatosPolitica('terminos');

export default function Pagina() {
  return <PaginaPolitica tipo="terminos" />;
}
