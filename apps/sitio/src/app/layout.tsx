import type { Metadata } from 'next';
import '@edunova/tokens/tokens.css';
import './globals.css';

export const metadata: Metadata = {
  title: 'Edunova · Gestión escolar',
  description: 'Sistema de gestión escolar para colegios privados del Perú.',
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="es">
      <body>{children}</body>
    </html>
  );
}
