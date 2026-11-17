import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, Switch, Text, View } from 'react-native';
import type { Cliente } from './api';
import { documentoValido, horaValida, separarPendientes } from './familiaColegioLogica';
import { Boton, Campo, colores, estilos, MensajeError } from './ui';

type Autorizacion = { id: number; titulo: string; contenido: string; vence: string; alumno_id: number; alumno: string; estado: 'pendiente' | 'acepta' | 'no_acepta' | 'vencida'; firmado_at: string | null };
type Documento = { id: number; tipo_nombre: string; nombre: string; version: string; requiere_lectura: boolean; leido_at: string | null };
type Autorizado = { id: number; alumno_id: number; alumno: string; nombres: string; numero_documento: string; parentesco: string; vigente_hasta: string | null; activo: boolean; vigente: boolean };
type Salida = { id: number; alumno: string; fecha: string; hora: string; motivo: string; estado: string; recoge: string; respuesta: string | null };
type Hijo = { id: number; nombre: string };

const ESTADO_SALIDA: Record<string, string> = { solicitada: 'Por confirmar', autorizada: 'Autorizada', salio: 'Salió', rechazada: 'No autorizada', anulada: 'Anulada' };
const hoy = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

function useHijos(api: Cliente): Hijo[] {
  const [hijos, setHijos] = useState<Hijo[]>([]);
  useEffect(() => {
    api.pedir<{ hijos: { alumno_id?: number; id?: number; nombres: string }[] }>('/me/familia')
      .then((r) => setHijos((r.hijos ?? []).map((x) => ({ id: Number(x.alumno_id ?? x.id), nombre: x.nombres }))))
      .catch(() => setHijos([]));
  }, [api]);
  return hijos;
}

function useEjecutar() {
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const ejecutar = async (fn: () => Promise<void>) => {
    setEnviando(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo completar.');
    } finally {
      setEnviando(false);
    }
  };
  return { enviando, error, ejecutar };
}

function Chips<T extends number | string>({ opciones, valor, alElegir, color }: { opciones: { id: T; nombre: string }[]; valor: T | null; alElegir: (v: T) => void; color: string }) {
  return (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
      {opciones.map((o) => (
        <Pressable key={String(o.id)} accessibilityRole="button" accessibilityState={{ selected: o.id === valor }} onPress={() => alElegir(o.id)}
          style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 8, borderWidth: 1, borderColor: color, backgroundColor: o.id === valor ? color : 'transparent' }}>
          <Text style={{ color: o.id === valor ? colores.blanco : color }}>{o.nombre}</Text>
        </Pressable>
      ))}
    </View>
  );
}

/** EDUN-122 / EDUN-121 · Autorizaciones por firmar y documentos del colegio con confirmación de lectura. */
export function AutorizacionesApp({ api, color }: { api: Cliente; color: string }) {
  const [auts, setAuts] = useState<Autorizacion[]>([]);
  const [docs, setDocs] = useState<Documento[]>([]);
  const { enviando, error, ejecutar } = useEjecutar();
  const cargar = useCallback(() => {
    api.pedir<{ data: Autorizacion[] }>('/familia/autorizaciones').then((r) => setAuts(r.data)).catch(() => setAuts([]));
    api.pedir<{ data: Documento[] }>('/familia/documentos').then((r) => setDocs(r.data)).catch(() => setDocs([]));
  }, [api]);
  useEffect(cargar, [cargar]);
  const { pendientes, resueltas } = separarPendientes(auts);
  if (!auts.length && !docs.length) return null;
  const firmar = (a: Autorizacion, acepta: boolean) => ejecutar(async () => {
    await api.pedir(`/familia/autorizaciones/${a.id}/firma`, { metodo: 'POST', cuerpo: { alumno_id: a.alumno_id, acepta } });
    cargar();
  });
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Autorizaciones y documentos</Text>
      {pendientes.map((a) => (
        <View key={`${a.id}-${a.alumno_id}`} style={{ gap: 6, paddingVertical: 8, borderBottomWidth: 1, borderColor: '#e5e7eb' }}>
          <Text style={{ color: colores.texto, fontWeight: '700' }}>{a.titulo} · {a.alumno}</Text>
          <Text style={{ color: colores.texto }}>{a.contenido}</Text>
          <Text style={estilos.subtitulo}>Firma hasta el {a.vence.split('-').reverse().join('/')}.</Text>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><Boton titulo="Autorizo" color={color} cargando={enviando} alPulsar={() => firmar(a, true)} /></View>
            <View style={{ flex: 1 }}><Boton titulo="No autorizo" borde color={color} alPulsar={() => firmar(a, false)} /></View>
          </View>
        </View>
      ))}
      {resueltas.slice(0, 3).map((a) => (
        <Text key={`${a.id}-${a.alumno_id}`} style={estilos.subtitulo}>{a.titulo} · {a.alumno}: {a.estado === 'acepta' ? 'autorizaste' : a.estado === 'vencida' ? 'venció sin firmar' : 'no autorizaste'}</Text>
      ))}
      {docs.map((d) => (
        <View key={d.id} style={[estilos.fila, { flexDirection: 'column', alignItems: 'stretch', gap: 6 }]}>
          <Text style={{ color: colores.texto }}>{d.nombre} · {d.tipo_nombre} (versión {d.version})</Text>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><Boton titulo="Leer" borde color={color} alPulsar={() => ejecutar(async () => { await Linking.openURL((await api.pedir<{ url: string }>(`/familia/documentos/${d.id}/archivo`)).url); })} /></View>
            {d.requiere_lectura && <View style={{ flex: 1 }}>{d.leido_at ? <Text style={{ color: '#15803d', paddingVertical: 14, textAlign: 'center' }}>Lectura confirmada</Text> : <Boton titulo="Leí y acepto" color={color} cargando={enviando} alPulsar={() => ejecutar(async () => { await api.pedir(`/familia/documentos/${d.id}/lectura`, { metodo: 'POST' }); cargar(); })} />}</View>}
          </View>
        </View>
      ))}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}

/** EDUN-187 / EDUN-189 · Quién puede recoger a mis hijos y salidas anticipadas. */
export function RecojoApp({ api, color }: { api: Cliente; color: string }) {
  const hijos = useHijos(api);
  const [autorizados, setAutorizados] = useState<Autorizado[]>([]);
  const [salidas, setSalidas] = useState<Salida[]>([]);
  const [modo, setModo] = useState<'nada' | 'autorizado' | 'salida'>('nada');
  const [alumno, setAlumno] = useState<number | null>(null);
  const [a, setA] = useState({ nombres: '', documento: '', parentesco: '', vigente: '' });
  const [s, setS] = useState({ fecha: hoy(), hora: '12:00', motivo: '', autorizado: 0 });
  const [mensaje, setMensaje] = useState<string | null>(null);
  const { enviando, error, ejecutar } = useEjecutar();
  const cargar = useCallback(() => {
    api.pedir<{ data: Autorizado[] }>('/familia/autorizados').then((r) => setAutorizados(r.data)).catch(() => undefined);
    api.pedir<{ data: Salida[] }>('/familia/salidas').then((r) => setSalidas(r.data)).catch(() => undefined);
  }, [api]);
  useEffect(cargar, [cargar]);
  if (!hijos.length) return null;
  const hijo = alumno ?? hijos[0].id;
  const delHijo = autorizados.filter((x) => x.alumno_id === hijo);

  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Recojo y salidas</Text>
      {hijos.length > 1 && <Chips opciones={hijos} valor={hijo} alElegir={setAlumno} color={color} />}
      {delHijo.map((x) => (
        <View key={x.id} style={estilos.fila}>
          <Text style={{ flex: 1, color: x.vigente ? colores.texto : colores.suave }}>{x.nombres} ({x.parentesco}) · {x.numero_documento}{x.vigente_hasta ? ` · hasta ${x.vigente_hasta.split('-').reverse().join('/')}` : ''}</Text>
          <Switch accessibilityLabel={`Autorizar a ${x.nombres}`} value={x.activo} trackColor={{ true: color }} onValueChange={(activo) => ejecutar(async () => {
            await api.pedir(`/familia/autorizados/${x.id}`, { metodo: 'PUT', cuerpo: { alumno_id: x.alumno_id, nombres: x.nombres, numero_documento: x.numero_documento, parentesco: x.parentesco, vigente_hasta: x.vigente_hasta, activo } });
            cargar();
          })} />
        </View>
      ))}
      {modo === 'autorizado' ? (
        <>
          <Campo etiqueta="Nombre completo" value={a.nombres} onChangeText={(nombres) => setA({ ...a, nombres })} />
          <Campo etiqueta="DNI o carné de extranjería" value={a.documento} onChangeText={(documento) => setA({ ...a, documento })} autoCapitalize="characters" />
          <Campo etiqueta="Parentesco" value={a.parentesco} onChangeText={(parentesco) => setA({ ...a, parentesco })} placeholder="Tía, abuelo, vecina…" />
          <Campo etiqueta="Vigente hasta (AAAA-MM-DD, opcional)" value={a.vigente} onChangeText={(vigente) => setA({ ...a, vigente })} keyboardType="numbers-and-punctuation" />
          <Boton titulo="Guardar" color={color} cargando={enviando} deshabilitado={a.nombres.trim().length < 3 || !documentoValido(a.documento) || a.parentesco.trim().length < 2} alPulsar={() => ejecutar(async () => {
            await api.pedir('/familia/autorizados', { metodo: 'POST', cuerpo: { alumno_id: hijo, nombres: a.nombres.trim(), numero_documento: a.documento, parentesco: a.parentesco.trim(), vigente_hasta: a.vigente || null } });
            setA({ nombres: '', documento: '', parentesco: '', vigente: '' });
            setModo('nada');
            setMensaje('Persona autorizada. Portería la verificará con su DNI.');
            cargar();
          })} />
          <Boton titulo="Cancelar" borde color={colores.suave} alPulsar={() => setModo('nada')} />
        </>
      ) : modo === 'salida' ? (
        <>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><Campo etiqueta="Día (AAAA-MM-DD)" value={s.fecha} onChangeText={(fecha) => setS({ ...s, fecha })} keyboardType="numbers-and-punctuation" /></View>
            <View style={{ flex: 1 }}><Campo etiqueta="Hora (HH:MM)" value={s.hora} onChangeText={(hora) => setS({ ...s, hora })} keyboardType="numbers-and-punctuation" /></View>
          </View>
          <Campo etiqueta="Motivo" value={s.motivo} onChangeText={(motivo) => setS({ ...s, motivo })} placeholder="Ej.: cita médica" />
          <Text style={estilos.subtitulo}>¿Quién lo recoge?</Text>
          <Chips opciones={[{ id: 0, nombre: 'Yo' }, ...delHijo.filter((x) => x.vigente).map((x) => ({ id: x.id, nombre: x.nombres }))]} valor={s.autorizado} alElegir={(autorizado) => setS({ ...s, autorizado })} color={color} />
          <Boton titulo="Pedir salida" color={color} cargando={enviando} deshabilitado={!horaValida(s.hora) || s.motivo.trim().length < 3} alPulsar={() => ejecutar(async () => {
            await api.pedir('/familia/salidas', { metodo: 'POST', cuerpo: { alumno_id: hijo, fecha: s.fecha, hora: s.hora, motivo: s.motivo.trim(), por_autorizado_id: s.autorizado || null } });
            setS({ ...s, motivo: '' });
            setModo('nada');
            setMensaje('Salida pedida. Te avisaremos cuando el colegio la confirme.');
            cargar();
          })} />
          <Boton titulo="Cancelar" borde color={colores.suave} alPulsar={() => setModo('nada')} />
        </>
      ) : (
        <View style={{ flexDirection: 'row', gap: 8 }}>
          <View style={{ flex: 1 }}><Boton titulo="Autorizar a alguien" borde color={color} alPulsar={() => { setModo('autorizado'); setMensaje(null); }} /></View>
          <View style={{ flex: 1 }}><Boton titulo="Salida anticipada" color={color} alPulsar={() => { setModo('salida'); setMensaje(null); }} /></View>
        </View>
      )}
      {salidas.slice(0, 3).map((x) => (
        <Text key={x.id} style={estilos.subtitulo}>{x.fecha.split('-').reverse().join('/')} {x.hora.slice(0, 5)} · {x.alumno} con {x.recoge}: {ESTADO_SALIDA[x.estado] ?? x.estado}{x.respuesta ? ` (${x.respuesta})` : ''}</Text>
      ))}
      {mensaje && <Text style={estilos.subtitulo}>{mensaje}</Text>}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}
