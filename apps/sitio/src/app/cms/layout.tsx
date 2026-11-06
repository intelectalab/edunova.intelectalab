import type { Metadata } from 'next';
import type { ReactNode } from 'react';

// Rutas del Panel CMS: nunca se indexan. La guarda de acceso está en (panel)/layout.tsx
// y en src/middleware.ts (EDUN-226).
export const metadata: Metadata = {
  robots: { index: false, follow: false },
};

export default function LayoutCms({ children }: { children: ReactNode }) {
  return children;
}
