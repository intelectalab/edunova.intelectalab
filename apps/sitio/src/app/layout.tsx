import type { Metadata } from 'next';
import { indexable, URL_SITIO } from '@/lib/seo';
import './globals.css';

export const metadata: Metadata = {
  metadataBase: new URL(URL_SITIO),
  title: 'Edunova',
  // EDUN-311: en staging nada se indexa; las páginas públicas lo permiten solo en producción.
  robots: indexable() ? undefined : { index: false, follow: false },
};

// Fuentes del prototipo: Lexend (títulos), Source Sans 3 (texto) y Material Symbols Outlined,
// con preconnect y display=swap (EDUN-220).
const FUENTES =
  'https://fonts.googleapis.com/css2?family=Lexend:wght@400;500;600;700&family=Source+Sans+3:wght@400;600;700&display=swap';
const ICONOS =
  'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap';

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="es-PE">
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
        <link rel="stylesheet" href={FUENTES} />
        <link rel="stylesheet" href={ICONOS} />
      </head>
      <body>{children}</body>
    </html>
  );
}
