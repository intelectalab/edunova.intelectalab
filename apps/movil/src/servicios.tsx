import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, Text, View } from 'react-native';
import type { Cliente } from './api';
import { distanciaLegible } from './serviciosLogica';
import { Boton, Campo, colores, estilos, MensajeError } from './ui';

type Hijo = { asignacion_id: number; alumno: string; ruta: string; placa: string | null; paradero: string; hora_estimada: string | null; recorrido: { estado: string } | null; en_vivo: { distancia_m: number | null; llegada_min: number | null; mapa_url: string; posicion_at: string } | null; bitacora: { tipo: string; ocurrido_at: string }[] };
type Ficha = { grupo_sanguineo: string | null; seguro: string | null; alergias: string | null; condiciones: string | null; medicacion: string | null; contacto_emergencia: string | null; telefono_emergencia: string | null };
const EVENTO: Record<string, string> = { subio: 'subió', bajo: 'bajó', no_subio: 'no subió' };
const hora = (iso: string) => new Date(iso).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' });

/** EDUN-166 · La familia sigue la movilidad: llegada estimada, mapa del teléfono y bitácora (se actualiza cada 20 s). */
export function MovilidadFamiliaApp({ api, color }: { api: Cliente; color: string }) {
  const [hijos, setHijos] = useState<Hijo[]>([]);
  const cargar = useCallback(() => {
    api.pedir<{ data: Hijo[] }>('/familia/movilidad').then((r) => setHijos(r.data)).catch(() => undefined);
  }, [api]);
  useEffect(() => {
    cargar();
    const t = setInterval(cargar, 20000);
    return () => clearInterval(t);
  }, [cargar]);
  if (!hijos.length) return null;
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Movilidad</Text>
      {hijos.map((h) => (
        <View key={h.asignacion_id} style={{ gap: 4, paddingVertical: 6 }}>
          <Text style={{ color: colores.texto, fontWeight: '600' }}>{h.alumno} · {h.ruta}{h.placa ? ` (${h.placa})` : ''}</Text>
          <Text style={estilos.subtitulo}>Paradero {h.paradero}{h.hora_estimada ? ` · ${h.hora_estimada}` : ''}</Text>
          {h.en_vivo ? (
            <>
              <Text style={{ color: '#15803d', fontWeight: '600' }}>En camino{h.en_vivo.distancia_m !== null ? `: a ${distanciaLegible(h.en_vivo.distancia_m)}` : ''}{h.en_vivo.llegada_min !== null ? `, llega en unos ${h.en_vivo.llegada_min} min` : ''}</Text>
              <Boton titulo="Ver en el mapa" borde color={color} alPulsar={() => void Linking.openURL(h.en_vivo!.mapa_url)} />
            </>
          ) : <Text style={estilos.subtitulo}>{h.recorrido?.estado === 'finalizado' ? 'El recorrido de hoy terminó.' : 'Todavía no inicia el recorrido.'}</Text>}
          {h.bitacora.map((e, i) => <Text key={i} style={estilos.subtitulo}>{hora(e.ocurrido_at)} · {EVENTO[e.tipo] ?? e.tipo}</Text>)}
        </View>
      ))}
    </View>
  );
}

const CAMPOS: [keyof Ficha, string][] = [['alergias', 'Alergias'], ['condiciones', 'Condiciones (asma, diabetes…)'], ['medicacion', 'Medicación'], ['contacto_emergencia', 'Contacto de emergencia'], ['telefono_emergencia', 'Teléfono de emergencia'], ['seguro', 'Seguro'], ['grupo_sanguineo', 'Grupo sanguíneo']];

/** EDUN-177 · La familia actualiza la ficha de salud desde la app (solo la ve enfermería). */
export function FichaSaludApp({ api, color }: { api: Cliente; color: string }) {
  const [hijos, setHijos] = useState<{ id: number; nombre: string }[]>([]);
  const [hijo, setHijo] = useState<number | null>(null);
  const [ficha, setFicha] = useState<Ficha | null>(null);
  const [abierta, setAbierta] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api.pedir<{ hijos: { alumno_id?: number; id?: number; nombres: string }[] }>('/me/familia')
      .then((r) => setHijos((r.hijos ?? []).map((x) => ({ id: Number(x.alumno_id ?? x.id), nombre: x.nombres })))).catch(() => setHijos([]));
  }, [api]);
  const id = hijo ?? hijos[0]?.id ?? null;
  useEffect(() => {
    if (!id || !abierta) return;
    api.pedir<{ ficha: Ficha | null }>(`/familia/salud/${id}`).then((r) => setFicha(r.ficha ?? ({} as Ficha))).catch((e) => setError(e instanceof Error ? e.message : 'No se pudo abrir.'));
  }, [api, id, abierta]);
  if (!hijos.length) return null;
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Ficha de salud</Text>
      {!abierta ? <Boton titulo="Ver y actualizar" borde color={color} alPulsar={() => { setAbierta(true); setMensaje(null); }} /> : (
        <>
          {hijos.length > 1 && (
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
              {hijos.map((h) => (
                <Pressable key={h.id} accessibilityRole="button" accessibilityState={{ selected: h.id === id }} onPress={() => { setHijo(h.id); setFicha(null); }} style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 8, borderWidth: 1, borderColor: color, backgroundColor: h.id === id ? color : 'transparent' }}>
                  <Text style={{ color: h.id === id ? colores.blanco : color }}>{h.nombre}</Text>
                </Pressable>
              ))}
            </View>
          )}
          {ficha && CAMPOS.map(([k, etiqueta]) => <Campo key={k} etiqueta={etiqueta} value={ficha[k] ?? ''} onChangeText={(v) => setFicha({ ...ficha, [k]: v })} multiline={['alergias', 'condiciones', 'medicacion'].includes(k)} keyboardType={k === 'telefono_emergencia' ? 'phone-pad' : 'default'} />)}
          <Boton titulo="Guardar" color={color} cargando={enviando} deshabilitado={!ficha} alPulsar={async () => {
            setEnviando(true);
            setError(null);
            try {
              await api.pedir(`/familia/salud/${id}`, { metodo: 'PUT', cuerpo: ficha });
              setMensaje('Ficha actualizada. Solo la ve enfermería.');
              setAbierta(false);
            } catch (e) {
              setError(e instanceof Error ? e.message : 'No se pudo guardar.');
            } finally {
              setEnviando(false);
            }
          }} />
        </>
      )}
      {mensaje && <Text style={estilos.subtitulo}>{mensaje}</Text>}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}

type Menu = { id: number; fecha: string; entrada: string | null; fondo: string; bebida: string | null; postre: string | null; kcal: number | null; alergenos: string | null };

/** EDUN-172 · La familia ve en la app el menú publicado de la semana. */
export function MenuComedorApp({ api }: { api: Cliente }) {
  const [menu, setMenu] = useState<Menu[]>([]);
  useEffect(() => {
    api.pedir<{ menu: Menu[] }>('/familia/comedor').then((r) => setMenu(r.menu)).catch(() => setMenu([]));
  }, [api]);
  if (!menu.length) return null;
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Menú del comedor</Text>
      {menu.map((m) => (
        <Text key={m.id} style={estilos.subtitulo}>{m.fecha.split('-').reverse().slice(0, 2).join('/')} · {[m.entrada, m.fondo, m.postre].filter(Boolean).join(', ')}{m.kcal ? ` · ${m.kcal} kcal` : ''}{m.alergenos ? ` · alérgenos: ${m.alergenos}` : ''}</Text>
      ))}
    </View>
  );
}
