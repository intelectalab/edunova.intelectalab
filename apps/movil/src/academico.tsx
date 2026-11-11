import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, Text, View } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { ErrorApi, type Cliente } from './api';
import { encolar, leerCola, sincronizar, type Lote } from './cola';
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
  const [pendientes, setPendientes] = useState(0);
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  const sincronizarCola = useCallback(async () => {
    const r = await sincronizar(AsyncStorage, async (l: Lote) => {
      await api.pedir(`/asistencia/secciones/${l.seccion_id}`, { metodo: 'PUT', cuerpo: { fecha: l.fecha, origen: 'offline', registros: l.registros } });
    });
    setPendientes(r.pendientes);
    if (r.enviados) setMensaje(`Se sincronizaron ${r.enviados} listas guardadas sin conexión.`);
  }, [api]);

  useEffect(() => {
    api.pedir<{ data: { id: number; nombre: string }[] }>('/asistencia/secciones').then((r) => {
      setSecciones(r.data);
      setSeccion((s) => s ?? r.data[0]?.id ?? null);
    }).catch(() => setSecciones([]));
    leerCola(AsyncStorage).then((c) => setPendientes(c.length));
    sincronizarCola().catch(() => undefined);
  }, [api, sincronizarCola]);

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
    try {
      await api.pedir(`/asistencia/secciones/${seccion}`, { metodo: 'PUT', cuerpo: { fecha: lote.fecha, origen: 'movil', registros: lote.registros } });
      setMensaje('Asistencia guardada.');
    } catch (e) {
      if (e instanceof ErrorApi && e.estado !== 0) {
        setError(e.message);
      } else {
        setPendientes(await encolar(AsyncStorage, lote));
        setMensaje('Sin conexión: la lista quedó guardada en el teléfono y se enviará al volver la señal.');
      }
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
      {pendientes > 0 && <Boton titulo={`Enviar ${pendientes} listas pendientes`} borde color={color} alPulsar={() => sincronizarCola().catch(() => setError('Aún sin conexión.'))} />}
      {mensaje && <Text style={estilos.subtitulo}>{mensaje}</Text>}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}

/** EDUN-95 · Registro de llegada en la puerta escaneando el QR del carné. */
export function PuertaQrApp({ api, color }: { api: Cliente; color: string }) {
  const [permiso, pedirPermiso] = useCameraPermissions();
  const [activo, setActivo] = useState(false);
  const [ultimo, setUltimo] = useState<string | null>(null);
  const [resultado, setResultado] = useState<{ texto: string; tarde: boolean } | null>(null);
  const leer = async (codigo: string) => {
    if (codigo === ultimo) return;
    setUltimo(codigo);
    try {
      const r = await api.pedir<{ alumno: string; seccion: string | null; estado: string; hora: string; repetido: boolean }>('/asistencia/qr', { metodo: 'POST', cuerpo: { codigo } });
      setResultado({ texto: `${r.alumno} · ${r.seccion ?? ''} · ${r.hora}${r.repetido ? ' (ya registrado)' : ''}`, tarde: r.estado === 'T' });
    } catch (e) {
      setResultado({ texto: e instanceof Error ? e.message : 'No se pudo registrar.', tarde: true });
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
      {resultado && <Text style={{ fontSize: 16, fontWeight: '600', color: resultado.tarde ? '#b45309' : '#15803d' }}>{resultado.tarde ? 'Tardanza: ' : 'Puntual: '}{resultado.texto}</Text>}
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
