import { useCallback, useEffect, useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import type { Cliente } from './api';
import { Boton, colores, estilos, MensajeError } from './ui';

type Comunicado = { id: number; titulo: string; contenido: string; enviado_at: string; requiere_confirmacion: boolean; leido_at: string | null; confirmado_at: string | null; alumno: string };
type Evento = { id: number; titulo: string; inicio: string; descripcion: string | null };

const fechaCorta = (iso: string) => new Date(iso).toLocaleString('es-PE', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

/** EDUN-62 · Comunicados en la app: push, lectura, confirmación y historial por hijo. */
export function ComunicadosApp({ api, color }: { api: Cliente; color: string }) {
  const [lista, setLista] = useState<Comunicado[] | null>(null);
  const [abierto, setAbierto] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const cargar = useCallback(() => api.pedir<{ data: Comunicado[] }>('/familia/comunicados').then((r) => setLista(r.data)).catch(() => setLista([])), [api]);
  useEffect(() => {
    cargar();
  }, [cargar]);
  if (!lista?.length) return null;
  const marcar = async (c: Comunicado, confirmar: boolean) => {
    try {
      setLista((await api.pedir<{ data: Comunicado[] }>(`/familia/comunicados/${c.id}`, { metodo: 'POST', cuerpo: { confirmar } })).data);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo marcar.');
    }
  };
  const sinLeer = lista.filter((c) => !c.leido_at).length;
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Comunicados{sinLeer ? ` · ${sinLeer} sin leer` : ''}</Text>
      {lista.slice(0, 8).map((c) => (
        <Pressable key={c.id} accessibilityRole="button" onPress={() => { setAbierto(abierto === c.id ? null : c.id); if (!c.leido_at) marcar(c, false); }} style={{ paddingVertical: 8, borderBottomWidth: 1, borderColor: '#e5e7eb' }}>
          <Text style={{ fontWeight: c.leido_at ? '400' : '700', color: colores.texto }}>{c.titulo}</Text>
          <Text style={{ color: colores.suave }}>{c.alumno} · {fechaCorta(c.enviado_at)}</Text>
          {abierto === c.id && (
            <View style={{ gap: 8, marginTop: 6 }}>
              <Text style={{ color: colores.texto }}>{c.contenido}</Text>
              {c.requiere_confirmacion && (c.confirmado_at ? <Text style={{ color: '#15803d' }}>Confirmado ✓</Text> : <Boton titulo="Confirmar lectura" color={color} alPulsar={() => marcar(c, true)} />)}
            </View>
          )}
        </Pressable>
      ))}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}

/** EDUN-64 · Próximos eventos del colegio para los hijos. */
export function AgendaApp({ api }: { api: Cliente }) {
  const [eventos, setEventos] = useState<Evento[] | null>(null);
  useEffect(() => {
    api.pedir<{ data: Evento[] }>('/agenda?familia=1').then((r) => setEventos(r.data.filter((e) => new Date(e.inicio) >= new Date(Date.now() - 86400000)))).catch(() => setEventos([]));
  }, [api]);
  if (!eventos?.length) return null;
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Agenda</Text>
      {eventos.slice(0, 6).map((e) => (
        <View key={e.id} style={estilos.fila}>
          <Text style={{ flex: 1, color: colores.texto }}>{e.titulo}</Text>
          <Text style={{ color: colores.suave }}>{fechaCorta(e.inicio)}</Text>
        </View>
      ))}
    </View>
  );
}
