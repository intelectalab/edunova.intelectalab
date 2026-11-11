import { useCallback, useEffect, useRef, useState } from 'react';
import { Linking, Pressable, Switch, Text, View } from 'react-native';
import * as Location from 'expo-location';
import type { Cliente } from './api';
import { Boton, Campo, colores, estilos, MensajeError } from './ui';

type Ruta = { id: number; nombre: string; placa: string | null; recorridos: { id: number; sentido: string; estado: string }[]; alumnos: { asignacion_id: number; nombres: string; apellido_paterno: string; paradero: string }[] };
type Cuota = { numero: number; vencimiento: string; monto: number; pagado: number; estado: string };
type Convenio = { id: number; numero: string; estado: string; monto_total: number; pagado: number; saldo: number; firmado_at: string | null; cuotas: Cuota[] };

const soles = (n: number) => `S/ ${n.toFixed(2)}`;

/**
 * EDUN-199 · Conductor y auxiliar de movilidad: inicia el recorrido, comparte la ubicación solo
 * mientras está en curso y marca quién subió o bajó (la familia recibe el aviso).
 */
export function RecorridoApp({ api, color }: { api: Cliente; color: string }) {
  const [rutas, setRutas] = useState<Ruta[] | null>(null);
  const [activo, setActivo] = useState<{ ruta: Ruta; recorrido: number; sentido: 'ida' | 'vuelta' } | null>(null);
  const [marcados, setMarcados] = useState<Record<number, string>>({});
  const [error, setError] = useState<string | null>(null);
  const reloj = useRef<ReturnType<typeof setInterval> | null>(null);
  const cargar = useCallback(() => api.pedir<{ data: Ruta[] }>('/movilidad/mis-rutas').then((r) => setRutas(r.data)).catch(() => setRutas([])), [api]);
  useEffect(() => {
    cargar();
    return () => { if (reloj.current) clearInterval(reloj.current); };
  }, [cargar]);
  if (!rutas?.length) return null;

  const enviarPosicion = async (recorrido: number) => {
    try {
      const p = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      await api.pedir(`/movilidad/recorridos/${recorrido}/posiciones`, { metodo: 'POST', cuerpo: { posiciones: [{
        latitud: p.coords.latitude, longitud: p.coords.longitude, velocidad_kmh: p.coords.speed !== null ? p.coords.speed * 3.6 : null,
        precision_m: p.coords.accuracy !== null ? Math.round(p.coords.accuracy) : null, registrado_at: new Date(p.timestamp).toISOString(),
      }] } });
    } catch {
      // Sin señal o sin GPS: se reintenta en el siguiente ciclo.
    }
  };
  const iniciar = async (ruta: Ruta, sentido: 'ida' | 'vuelta') => {
    setError(null);
    const permiso = await Location.requestForegroundPermissionsAsync();
    if (permiso.status !== 'granted') return setError('Da permiso de ubicación para compartir el recorrido con las familias.');
    try {
      const r = await api.pedir<{ recorrido_id: number }>(`/movilidad/rutas/${ruta.id}/recorridos`, { metodo: 'POST', cuerpo: { sentido } });
      setActivo({ ruta, recorrido: r.recorrido_id, sentido });
      setMarcados({});
      await enviarPosicion(r.recorrido_id);
      reloj.current = setInterval(() => enviarPosicion(r.recorrido_id), 20000);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo iniciar.');
    }
  };
  const finalizar = async () => {
    if (!activo) return;
    if (reloj.current) clearInterval(reloj.current);
    await api.pedir(`/movilidad/recorridos/${activo.recorrido}/fin`, { metodo: 'POST' }).catch(() => undefined);
    setActivo(null);
    cargar();
  };
  const marcar = async (asignacion: number, tipo: 'subio' | 'bajo' | 'no_subio') => {
    try {
      await api.pedir(`/movilidad/recorridos/${activo!.recorrido}/eventos`, { metodo: 'POST', cuerpo: { asignacion_id: asignacion, tipo } });
      setMarcados({ ...marcados, [asignacion]: tipo });
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo registrar.');
    }
  };

  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Movilidad escolar</Text>
      {!activo ? rutas.map((r) => (
        <View key={r.id} style={{ gap: 6 }}>
          <Text style={{ color: colores.texto, fontWeight: '600' }}>{r.nombre}{r.placa ? ` · ${r.placa}` : ''} · {r.alumnos.length} alumnos</Text>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><Boton titulo="Iniciar ida" color={color} alPulsar={() => iniciar(r, 'ida')} /></View>
            <View style={{ flex: 1 }}><Boton titulo="Iniciar vuelta" borde color={color} alPulsar={() => iniciar(r, 'vuelta')} /></View>
          </View>
        </View>
      )) : (
        <>
          <Text style={{ color: '#15803d' }}>En curso: {activo.ruta.nombre}. Se comparte la ubicación cada 20 segundos.</Text>
          {activo.ruta.alumnos.map((a) => (
            <View key={a.asignacion_id} style={estilos.fila}>
              <Text style={{ flex: 1, color: colores.texto }}>{a.nombres} {a.apellido_paterno}{'\n'}<Text style={{ color: colores.suave }}>{a.paradero}</Text></Text>
              {marcados[a.asignacion_id] ? <Text style={{ color: colores.suave }}>{marcados[a.asignacion_id].replace('_', ' ')}</Text> : (
                <View style={{ flexDirection: 'row', gap: 6 }}>
                  <Pressable accessibilityRole="button" onPress={() => marcar(a.asignacion_id, activo.sentido === 'vuelta' ? 'bajo' : 'subio')} style={{ padding: 8, borderRadius: 8, backgroundColor: color }}><Text style={{ color: '#fff' }}>✓</Text></Pressable>
                  <Pressable accessibilityRole="button" onPress={() => marcar(a.asignacion_id, 'no_subio')} style={{ padding: 8, borderRadius: 8, borderWidth: 1, borderColor: colores.error }}><Text style={{ color: colores.error }}>✕</Text></Pressable>
                </View>
              )}
            </View>
          ))}
          <Boton titulo="Finalizar recorrido" borde color={colores.error} alPulsar={finalizar} />
        </>
      )}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}

/** EDUN-199 · Enfermería: atención rápida en tópico con aviso al padre. */
export function EnfermeriaApp({ api, color }: { api: Cliente; color: string }) {
  const [q, setQ] = useState('');
  const [resultados, setResultados] = useState<{ id: number; nombres: string; apellido_paterno: string }[]>([]);
  const [alumno, setAlumno] = useState<{ id: number; nombres: string; apellido_paterno: string } | null>(null);
  const [motivo, setMotivo] = useState('');
  const [accion, setAccion] = useState('');
  const [derivado, setDerivado] = useState(false);
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const buscar = async () => {
    try {
      setResultados((await api.pedir<{ data: { id: number; nombres: string; apellido_paterno: string }[] }>(`/salud/alumnos?q=${encodeURIComponent(q.trim())}`)).data);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo buscar.');
    }
  };
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Tópico: atención rápida</Text>
      {!alumno ? (
        <>
          <Campo etiqueta="Alumno (nombre o código del carné)" value={q} onChangeText={setQ} onSubmitEditing={buscar} />
          <Boton titulo="Buscar" borde color={color} deshabilitado={q.trim().length < 3} alPulsar={buscar} />
          {resultados.map((a) => <Pressable key={a.id} accessibilityRole="button" onPress={() => setAlumno(a)} style={estilos.fila}><Text style={{ color: colores.texto }}>{a.apellido_paterno}, {a.nombres}</Text></Pressable>)}
        </>
      ) : (
        <>
          <Text style={{ color: colores.texto, fontWeight: '600' }}>{alumno.nombres} {alumno.apellido_paterno}</Text>
          <Campo etiqueta="Motivo" value={motivo} onChangeText={setMotivo} />
          <Campo etiqueta="Qué se hizo" value={accion} onChangeText={setAccion} multiline />
          <View style={estilos.fila}><Text style={{ flex: 1, color: colores.texto }}>Derivado (requiere que lo recojan)</Text><Switch accessibilityLabel="Derivado" value={derivado} trackColor={{ true: color }} onValueChange={setDerivado} /></View>
          <Boton titulo="Registrar y avisar a la familia" color={color} deshabilitado={!motivo.trim() || !accion.trim()} alPulsar={async () => {
            try {
              await api.pedir('/salud/atenciones', { metodo: 'POST', cuerpo: { alumno_id: alumno.id, motivo, accion, derivado, avisar_padre: true } });
              setMensaje(`Atención de ${alumno.nombres} registrada; la familia fue avisada.`);
              setAlumno(null); setMotivo(''); setAccion(''); setDerivado(false); setQ(''); setResultados([]);
            } catch (e) {
              setError(e instanceof Error ? e.message : 'No se pudo registrar.');
            }
          }} />
          <Boton titulo="Cancelar" borde color={colores.suave} alPulsar={() => setAlumno(null)} />
        </>
      )}
      {mensaje && <Text style={estilos.subtitulo}>{mensaje}</Text>}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}

/** EDUN-87 · Convenio en la app: cuotas, pagado y saldo; firmar y pagar la cuota. */
export function ConveniosApp({ api, color }: { api: Cliente; color: string }) {
  const [lista, setLista] = useState<Convenio[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const cargar = useCallback(() => api.pedir<{ data: Convenio[] }>('/familia/convenios').then((r) => setLista(r.data)).catch(() => setLista([])), [api]);
  useEffect(() => {
    cargar();
  }, [cargar]);
  if (!lista?.length) return null;
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Mis convenios</Text>
      {lista.map((c) => {
        const proxima = c.cuotas.find((q) => q.estado !== 'pagada');
        return (
          <View key={c.id} style={{ gap: 6 }}>
            <Text style={{ color: colores.texto, fontWeight: '600' }}>{c.numero} · {c.estado}</Text>
            <Text style={{ color: colores.suave }}>Pagado {soles(c.pagado)} · saldo {soles(c.saldo)}</Text>
            {c.cuotas.map((q) => <Text key={q.numero} style={{ color: q.estado === 'vencida' ? colores.error : colores.texto }}>Cuota {q.numero}: {q.vencimiento} · {soles(q.monto)} · {q.estado}</Text>)}
            {c.estado === 'borrador' && !c.firmado_at && <Boton titulo="Firmar convenio" color={color} alPulsar={async () => {
              try { await api.pedir(`/familia/convenios/${c.id}/firma`, { metodo: 'POST', cuerpo: { acepto: true } }); cargar(); } catch (e) { setError(e instanceof Error ? e.message : 'No se pudo firmar.'); }
            }} />}
            {c.estado === 'vigente' && proxima && <Boton titulo={`Pagar cuota ${proxima.numero} (${soles(proxima.monto - proxima.pagado)})`} color={color} alPulsar={async () => {
              try { await Linking.openURL((await api.pedir<{ url: string }>(`/familia/convenios/${c.id}/cuotas/${proxima.numero}/pago`, { metodo: 'POST' })).url); } catch (e) { setError(e instanceof Error ? e.message : 'No se pudo iniciar el pago.'); }
            }} />}
          </View>
        );
      })}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}
