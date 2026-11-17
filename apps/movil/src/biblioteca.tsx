import { useCallback, useEffect, useState } from 'react';
import { Linking, Text, View } from 'react-native';
import type { Cliente } from './api';
import { Boton, Campo, estilos, MensajeError } from './ui';

type Mia = { prestamos: { id: number; titulo: string; vence: string; alumno: string; vencido: boolean }[]; reservas: { id: number; estado: string; titulo: string }[]; digitales: { id: number; titulo: string; vence: string }[] };
type Libro = { id: number; titulo: string; autor: string | null; tipo: string; disponibles: number };
const dm = (iso: string) => iso.slice(0, 10).split('-').reverse().slice(0, 2).join('/');

/** EDUN-154 / EDUN-157 · Biblioteca en la app: prestados, reservas (con aviso) y libros digitales. */
export function BibliotecaApp({ api, color }: { api: Cliente; color: string }) {
  const [mia, setMia] = useState<Mia | null>(null);
  const [buscar, setBuscar] = useState('');
  const [libros, setLibros] = useState<Libro[]>([]);
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const cargar = useCallback(() => {
    api.pedir<Mia>('/biblioteca/mia').then(setMia).catch(() => setMia(null));
  }, [api]);
  useEffect(cargar, [cargar]);
  if (!mia) return null;
  const ejecutar = async (fn: () => Promise<void>) => {
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo completar.');
    }
  };
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Biblioteca</Text>
      {mia.prestamos.map((p) => <Text key={p.id} style={[estilos.subtitulo, p.vencido && { color: '#b91c1c' }]}>{p.titulo} · {p.alumno} · devolver el {dm(p.vence)}{p.vencido ? ' (vencido)' : ''}</Text>)}
      {mia.reservas.map((r) => <Text key={r.id} style={estilos.subtitulo}>Reserva: {r.titulo} · {r.estado === 'disponible' ? 'lista para recoger' : 'en espera'}</Text>)}
      {mia.digitales.map((d) => <Boton key={d.id} titulo={`Seguir leyendo: ${d.titulo}`} borde color={color} alPulsar={() => ejecutar(async () => { await Linking.openURL((await api.pedir<{ url: string }>(`/biblioteca/digitales/${d.id}/lectura`, { metodo: 'POST' })).url); })} />)}
      <Campo etiqueta="Buscar un libro" value={buscar} onChangeText={setBuscar} returnKeyType="search" onSubmitEditing={() => ejecutar(async () => setLibros((await api.pedir<{ data: Libro[] }>(`/biblioteca/catalogo?buscar=${encodeURIComponent(buscar.trim())}`)).data.slice(0, 8)))} />
      {libros.map((l) => (
        <View key={l.id} style={{ gap: 4 }}>
          <Text style={{ fontWeight: '600' }}>{l.titulo}{l.autor ? ` · ${l.autor}` : ''}</Text>
          {l.tipo === 'ebook' ? (
            <Boton titulo="Leer" color={color} alPulsar={() => ejecutar(async () => { await Linking.openURL((await api.pedir<{ url: string }>(`/biblioteca/digitales/${l.id}/lectura`, { metodo: 'POST' })).url); cargar(); })} />
          ) : (
            <Boton titulo={l.disponibles ? 'Reservar (hay disponibles)' : 'Reservar y avisarme'} borde color={color} alPulsar={() => ejecutar(async () => {
              const r = await api.pedir<{ estado: string }>('/biblioteca/reservas', { metodo: 'POST', cuerpo: { bib_libro_id: l.id } });
              setMensaje(r.estado === 'disponible' ? 'Reservado: recógelo en la biblioteca.' : 'Reservado: te avisaremos cuando vuelva.');
              cargar();
            })} />
          )}
        </View>
      ))}
      {mensaje && <Text style={estilos.subtitulo}>{mensaje}</Text>}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}
