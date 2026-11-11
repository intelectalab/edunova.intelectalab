import { useCallback, useEffect, useState } from 'react';
import { Image, Pressable, Text, View } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import type { Cliente } from './api';
import { Boton, Campo, colores, estilos, MensajeError } from './ui';

type Justificacion = { id: number; alumno: string; fecha_desde: string; fecha_hasta: string; motivo: string; estado: 'pendiente' | 'aceptada' | 'rechazada'; respuesta: string | null; dias_justificados: number };
type Hijo = { id: number; nombre: string };

const hoy = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const ESTADO: Record<Justificacion['estado'], { texto: string; color: string }> = {
  pendiente: { texto: 'En revisión del tutor', color: '#b45309' },
  aceptada: { texto: 'Aceptada', color: '#15803d' },
  rechazada: { texto: 'Rechazada', color: colores.error },
};

/** Fecha válida AAAA-MM-DD (lo que acepta la API). */
export const fechaValida = (f: string) => /^\d{4}-\d{2}-\d{2}$/.test(f) && !Number.isNaN(Date.parse(f));

/**
 * EDUN-96 · La familia justifica una inasistencia desde la app: hijo, días, motivo y la foto del
 * sustento (cámara o galería). El tutor la acepta o rechaza y llega el aviso.
 */
export function JustificarApp({ api, color }: { api: Cliente; color: string }) {
  const [hijos, setHijos] = useState<Hijo[]>([]);
  const [lista, setLista] = useState<Justificacion[]>([]);
  const [abierto, setAbierto] = useState(false);
  const [alumno, setAlumno] = useState<number | null>(null);
  const [desde, setDesde] = useState(hoy());
  const [hasta, setHasta] = useState(hoy());
  const [motivo, setMotivo] = useState('');
  const [foto, setFoto] = useState<ImagePicker.ImagePickerAsset | null>(null);
  const [enviando, setEnviando] = useState(false);
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const cargar = useCallback(() => {
    api.pedir<{ data: Justificacion[] }>('/familia/justificaciones').then((r) => setLista(r.data)).catch(() => undefined);
  }, [api]);
  useEffect(() => {
    api.pedir<{ hijos: { alumno_id?: number; id?: number; nombres: string; apellido_paterno?: string }[] }>('/me/familia')
      .then((r) => {
        const h = (r.hijos ?? []).map((x) => ({ id: Number(x.alumno_id ?? x.id), nombre: `${x.nombres} ${x.apellido_paterno ?? ''}`.trim() }));
        setHijos(h);
        setAlumno((a) => a ?? h[0]?.id ?? null);
      })
      .catch(() => setHijos([]));
    cargar();
  }, [api, cargar]);
  if (!hijos.length) return null;

  const elegirFoto = async (camara: boolean) => {
    setError(null);
    const permiso = camara ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permiso.granted) return setError('Da permiso para adjuntar la foto del sustento.');
    const r = camara
      ? await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 0.6 })
      : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.6 });
    if (!r.canceled) setFoto(r.assets[0]);
  };

  const enviar = async () => {
    if (!alumno) return;
    if (!fechaValida(desde) || !fechaValida(hasta)) return setError('Escribe las fechas como AAAA-MM-DD.');
    setEnviando(true);
    setError(null);
    try {
      const f = new FormData();
      f.append('alumno_id', String(alumno));
      f.append('fecha_desde', desde);
      f.append('fecha_hasta', hasta);
      f.append('motivo', motivo.trim());
      if (foto) f.append('sustento', { uri: foto.uri, name: foto.fileName ?? 'sustento.jpg', type: foto.mimeType ?? 'image/jpeg' } as unknown as Blob);
      await api.pedir('/familia/justificaciones', { metodo: 'POST', cuerpo: f });
      setMensaje('Justificación enviada. El tutor la revisará y te avisaremos.');
      setAbierto(false);
      setMotivo('');
      setFoto(null);
      cargar();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo enviar.');
    } finally {
      setEnviando(false);
    }
  };

  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Justificar una inasistencia</Text>
      {!abierto ? (
        <Boton titulo="Nueva justificación" color={color} alPulsar={() => { setAbierto(true); setMensaje(null); }} />
      ) : (
        <>
          {hijos.length > 1 && (
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
              {hijos.map((h) => (
                <Pressable key={h.id} accessibilityRole="button" accessibilityState={{ selected: h.id === alumno }} onPress={() => setAlumno(h.id)} style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 8, borderWidth: 1, borderColor: color, backgroundColor: h.id === alumno ? color : 'transparent' }}>
                  <Text style={{ color: h.id === alumno ? colores.blanco : color }}>{h.nombre}</Text>
                </Pressable>
              ))}
            </View>
          )}
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><Campo etiqueta="Desde (AAAA-MM-DD)" value={desde} onChangeText={setDesde} keyboardType="numbers-and-punctuation" /></View>
            <View style={{ flex: 1 }}><Campo etiqueta="Hasta" value={hasta} onChangeText={setHasta} keyboardType="numbers-and-punctuation" /></View>
          </View>
          <Campo etiqueta="Motivo" value={motivo} onChangeText={setMotivo} multiline placeholder="Ej.: fiebre, cita médica, viaje familiar" />
          <Text style={estilos.subtitulo}>Sustento (opcional pero recomendado): receta, constancia o certificado.</Text>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><Boton titulo="Tomar foto" borde color={color} alPulsar={() => elegirFoto(true)} /></View>
            <View style={{ flex: 1 }}><Boton titulo="Elegir de la galería" borde color={color} alPulsar={() => elegirFoto(false)} /></View>
          </View>
          {foto && <Image source={{ uri: foto.uri }} accessibilityLabel="Foto del sustento" style={{ width: '100%', height: 160, borderRadius: 8 }} resizeMode="cover" />}
          <Boton titulo="Enviar al tutor" color={color} cargando={enviando} deshabilitado={motivo.trim().length < 5} alPulsar={enviar} />
          <Boton titulo="Cancelar" borde color={colores.suave} alPulsar={() => setAbierto(false)} />
        </>
      )}
      {mensaje && <Text style={estilos.subtitulo}>{mensaje}</Text>}
      <MensajeError>{error}</MensajeError>
      {lista.slice(0, 5).map((j) => (
        <View key={j.id} style={[estilos.fila, { flexDirection: 'column', alignItems: 'flex-start', gap: 2 }]}>
          <Text style={{ color: colores.texto, fontWeight: '600' }}>{j.alumno} · {j.fecha_desde === j.fecha_hasta ? j.fecha_desde : `${j.fecha_desde} al ${j.fecha_hasta}`}</Text>
          <Text style={{ color: ESTADO[j.estado].color }}>{ESTADO[j.estado].texto}{j.estado === 'aceptada' && j.dias_justificados ? ` · ${j.dias_justificados} día(s)` : ''}</Text>
          {j.respuesta && <Text style={estilos.subtitulo}>{j.respuesta}</Text>}
        </View>
      ))}
    </View>
  );
}
