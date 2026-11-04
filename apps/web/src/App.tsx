// Esqueleto de la web de gestión (EDUN-415). Las pantallas reales llegan con cada módulo.
const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8100/api/v1';

export default function App() {
  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <aside
        style={{ width: 240, background: 'var(--en-menu-lateral)', color: '#fff', padding: '24px 16px' }}
      >
        <strong style={{ fontFamily: 'var(--en-fuente-titulos)', fontSize: 20 }}>Edunova</strong>
      </aside>
      <main style={{ flex: 1, padding: 32, background: 'var(--en-tarjeta)' }}>
        <h1>Web de gestión</h1>
        <p style={{ color: 'var(--en-texto2)' }}>Esqueleto del monorepo. API: {API_URL}</p>
      </main>
    </div>
  );
}
