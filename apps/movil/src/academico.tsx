import { useEffect, useState } from 'react';
import { Linking, Pressable, Text, View } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { ErrorApi, type Cliente } from './api';
import type { Lote } from './cola';
import { descartarOperacion, guardarLectura, guardarLista, reintentarOperacion, sincronizarAhora, useEstadoCola } from './colaDispositivo';
import { Boton, colores, estilos, MensajeError } from './ui';

type Alumno = { id: number; nombre: string; estado: 'P' | 'T' | 'F' | 'J' };
const hoy = () => new Date().toISOString().slice(0, 10);

/**
 * EDUN-94 · Asistencia desde el celular: todos presentes por defecto, T o F en un toque y, sin
 * señal, se guarda en el teléfono y se sincroniza al volver la conexión.
 */
export function AsistenciaApp({ api, color }: { api: Cliente; color: string }) {
  const [secciones, setSecciones] = useState<{ id: number; nombre: string }[]>([]);
  const [seccion, setSeccion] = useState<number | null>(null);
  const [alumnos, setAlumnos] = useState<Alumno[] | null>(null);
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);
  const red = useEstadoCola();

  useEffect(() => {
    api.pedir<{ data: { id: number; nombre: string }[] }>('/asistencia/secciones').then((r) => {
      setSecciones(r.data);
      setSeccion((s) => s ?? r.data[0]?.id ?? null);
    }).catch(() => setSecciones([]));
  }, [api]);

  useEffect(() => {
    if (!seccion) return;
    api.pedir<{ alumnos: Alumno[] }>(`/asistencia/secciones/${seccion}?fecha=${hoy()}`).then((r) => setAlumnos(r.alumnos)).catch((e) => setError(e instanceof Error ? e.message : 'Sin conexión.'));
  }, [api, seccion]);

  if (!secciones.length) return null;
  const marcar = (id: number) => setAlumnos((as) => as!.map((a) => (a.id === id ? { ...a, estado: a.estado === 'P' ? 'T' : a.estado === 'T' ? 'F' : 'P' } : a)));
  const guardar = async () => {
    if (!seccion || !alumnos) return;
    setEnviando(true);
    setError(null);
    const lote: Lote = { seccion_id: seccion, fecha: hoy(), registros: alumnos.map((a) => ({ alumno_id: a.id, estado: a.estado, registrado_at: new Date().toISOString() })) };
    const aCola = async () => {
      await guardarLista(lote);
      setMensaje('Sin conexión: la lista quedó guardada (cifrada) en el teléfono y se enviará al volver la señal.');
    };
    try {
      if (!red.enLinea) return await aCola();
      await api.pedir(`/asistencia/secciones/${seccion}`, { metodo: 'PUT', cuerpo: { fecha: lote.fecha, origen: 'movil', registros: lote.registros } });
      setMensaje('Asistencia guardada.');
    } catch (e) {
      if (e instanceof ErrorApi && e.estado !== 0) setError(e.message);
      else await aCola();
    } finally {
      setEnviando(false);
    }
  };

  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Asistencia de hoy</Text>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
        {secciones.map((s) => (
          <Pressable key={s.id} accessibilityRole="button" onPress={() => setSeccion(s.id)} style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 8, borderWidth: 1, borderColor: color, backgroundColor: s.id === seccion ? color : 'transparent' }}>
            <Text style={{ color: s.id === seccion ? colores.blanco : color }}>{s.nombre}</Text>
          </Pressable>
        ))}
      </View>
      <Text style={estilos.subtitulo}>Toca a un alumno para cambiar: P → T → F.</Text>
      {alumnos?.map((a) => (
        <Pressable key={a.id} accessibilityRole="button" accessibilityLabel={`${a.nombre}: ${a.estado}`} onPress={() => marcar(a.id)} style={estilos.fila}>
          <Text style={{ flex: 1, color: colores.texto }}>{a.nombre}</Text>
          <Text style={{ fontWeight: '700', color: a.estado === 'P' ? '#15803d' : a.estado === 'T' ? '#b45309' : colores.error }}>{a.estado}</Text>
        </Pressable>
      ))}
      <Boton titulo="Guardar asistencia" color={color} cargando={enviando} alPulsar={guardar} />
      {mensaje && <Text style={estilos.subtitulo}>{mensaje}</Text>}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}

/**
 * EDUN-217 · Indicador de conexión: en línea o sin conexión, cuántos registros esperan envío y
 * los que quedaron con error (para reintentar o descartar).
 */
export function EstadoConexion({ color }: { color: string }) {
  const red = useEstadoCola();
  if (red.enLinea && !red.pendientes && !red.errores.length && !red.mensaje) return null;
  return (
    <View style={[estilos.tarjeta, { borderLeftWidth: 4, borderLeftColor: red.enLinea ? '#15803d' : '#b45309' }]} accessibilityLiveRegion="polite">
      <Text style={{ fontWeight: '700', color: red.enLinea ? '#15803d' : '#b45309' }}>
        {red.enLinea ? '● En línea' : '● Sin conexión'}{red.pendientes ? ` · ${red.pendientes} por enviar` : ''}{red.sincronizando ? ' · enviando…' : ''}
      </Text>
      {!red.enLinea && <Text style={estilos.subtitulo}>Lo que registres se guarda cifrado en el teléfono y se envía solo al volver la señal.</Text>}
      {red.mensaje && <Text style={estilos.subtitulo}>{red.mensaje}</Text>}
      {red.enLinea && red.pendientes > 0 && !red.sincronizando && <Boton titulo="Enviar ahora" borde color={color} alPulsar={() => sincronizarAhora().catch(() => undefined)} />}
      {red.errores.map((o) => (
        <View key={o.id} style={{ gap: 4 }}>
          <Text style={{ color: colores.error }}>{o.tipo === 'qr' ? `Lectura de carné ${o.datos.leido_at.slice(0, 16).replace('T', ' ')}` : `Lista del ${o.datos.fecha}`}: {o.error}</Text>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><Boton titulo="Reintentar" borde color={color} alPulsar={() => reintentarOperacion(o.id).catch(() => undefined)} /></View>
            <View style={{ flex: 1 }}><Boton titulo="Descartar" borde color={colores.error} alPulsar={() => descartarOperacion(o.id).catch(() => undefined)} /></View>
          </View>
        </View>
      ))}
    </View>
  );
}

/** EDUN-95 · Registro de llegada en la puerta escaneando el QR del carné. */
export function PuertaQrApp({ api, color }: { api: Cliente; color: string }) {
  const [permiso, pedirPermiso] = useCameraPermissions();
  const [activo, setActivo] = useState(false);
  const [ultimo, setUltimo] = useState<string | null>(null);
  const [resultado, setResultado] = useState<{ texto: string; tarde: boolean; guardado?: boolean } | null>(null);
  const red = useEstadoCola();
  const leer = async (codigo: string) => {
    if (codigo === ultimo) return;
    setUltimo(codigo);
    const leidoAt = new Date();
    const aCola = async () => {
      await guardarLectura({ codigo, leido_at: leidoAt.toISOString() });
      setResultado({ texto: `Sin conexión: lectura guardada (${leidoAt.toTimeString().slice(0, 5)}); se envía al volver la señal.`, tarde: false, guardado: true });
    };
    try {
      if (!red.enLinea) await aCola();
      else {
        const r = await api.pedir<{ alumno: string; seccion: string | null; estado: string; hora: string; repetido: boolean }>('/asistencia/qr', { metodo: 'POST', cuerpo: { codigo } });
        setResultado({ texto: `${r.alumno} · ${r.seccion ?? ''} · ${r.hora}${r.repetido ? ' (ya registrado)' : ''}`, tarde: r.estado === 'T' });
      }
    } catch (e) {
      if (e instanceof ErrorApi && e.estado === 0) await aCola();
      else setResultado({ texto: e instanceof Error ? e.message : 'No se pudo registrar.', tarde: true });
    }
    setTimeout(() => setUltimo(null), 2500);
  };
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Puerta: QR del carné</Text>
      {!activo ? (
        <Boton titulo="Abrir escáner" color={color} alPulsar={async () => { if (!permiso?.granted) await pedirPermiso(); setActivo(true); }} />
      ) : permiso?.granted ? (
        <>
          <CameraView style={{ height: 260, borderRadius: 12 }} barcodeScannerSettings={{ barcodeTypes: ['qr'] }} onBarcodeScanned={({ data }) => leer(data)} />
          <Boton titulo="Cerrar escáner" borde color={color} alPulsar={() => setActivo(false)} />
        </>
      ) : <Text style={estilos.subtitulo}>Da permiso de cámara para escanear los carnés.</Text>}
      {resultado && <Text style={{ fontSize: 16, fontWeight: '600', color: resultado.guardado ? colores.suave : resultado.tarde ? '#b45309' : '#15803d' }}>{resultado.guardado ? '' : resultado.tarde ? 'Tardanza: ' : 'Puntual: '}{resultado.texto}</Text>}
    </View>
  );
}

type PeriodoNotas = { id: number; periodo_id: number; periodo: string; areas: { area: string; competencias: { numero: number; valor: string | null; competencia: string }[] }[] };

/** EDUN-100 · Notas y libreta por hijo y periodo en la app (sin bloqueo por deuda). */
export function NotasApp({ api, color }: { api: Cliente; color: string }) {
  const [hijos, setHijos] = useState<{ alumno_id: number; alumno: string; periodos: PeriodoNotas[] }[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api.pedir<{ hijos: { alumno_id: number; alumno: string; periodos: PeriodoNotas[] }[] }>('/familia/notas').then((r) => setHijos(r.hijos)).catch(() => setHijos([]));
  }, [api]);
  if (!hijos?.length) return null;
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Notas y libretas</Text>
      {hijos.map((h) => {
        const p = h.periodos[h.periodos.length - 1];
        return (
          <View key={h.alumno_id} style={{ gap: 4 }}>
            <Text style={{ fontSize: 16, fontWeight: '700', color: colores.texto }}>{h.alumno} · {p.periodo}</Text>
            {p.areas.map((a) => (
              <Text key={a.area} style={{ color: colores.texto }}>{a.area}: {a.competencias.map((k) => k.valor ?? '—').join(' · ')}</Text>
            ))}
            <Boton titulo="Descargar libreta" borde color={color} alPulsar={async () => {
              try {
                await Linking.openURL((await api.pedir<{ url: string }>(`/familia/libretas/${p.id}`)).url);
              } catch (e) {
                setError(e instanceof Error ? e.message : 'No se pudo abrir la libreta.');
              }
            }} />
          </View>
        );
      })}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}
