import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, Text, View } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import type { Cliente } from './api';
import { requisitosFaltantes, type Requisito } from './tramitesLogica';
import { Boton, Campo, colores, estilos, MensajeError } from './ui';

type Tipo = { id: number; nombre: string; descripcion: string | null; costo: number; plazo_dias: number; requiere_sin_deuda: boolean; requisitos: Requisito[] };
type Tramite = { id: number; numero: string; tipo: string; alumno: string; estado: Estado; cargo_monto: number | null; pagado: boolean; anulado: boolean };
type Estado = 'solicitado' | 'observado' | 'en_revision' | 'por_firmar' | 'entregado' | 'rechazado';
type Hijo = { id: number; nombre: string };
type Foto = { uri: string; name: string; type: string };

const ESTADO_TRAMITE: Record<Estado, { texto: string; color: string }> = {
  solicitado: { texto: 'Enviado al colegio', color: '#b45309' },
  observado: { texto: 'Observado: revisa y responde', color: colores.error },
  en_revision: { texto: 'En revisión', color: '#b45309' },
  por_firmar: { texto: 'Por firmar', color: '#b45309' },
  entregado: { texto: 'Listo para descargar', color: '#15803d' },
  rechazado: { texto: 'Rechazado', color: colores.error },
};

const soles = (n: number) => `S/ ${n.toFixed(2)}`;

async function tomarFoto(camara: boolean): Promise<Foto | null> {
  const permiso = camara ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
  if (!permiso.granted) throw new Error('Da permiso para adjuntar la foto del requisito.');
  const r = camara ? await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 0.6 }) : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.6 });
  if (r.canceled) return null;
  const a = r.assets[0];
  return { uri: a.uri, name: a.fileName ?? 'requisito.jpg', type: a.mimeType ?? 'image/jpeg' };
}

/**
 * EDUN-114 · La familia pide un trámite desde la app: elige el tipo y el hijo, fotografía los
 * requisitos y, si tiene costo, lo paga en Mis pagos. Cuando el colegio lo firma, descarga el PDF.
 */
export function TramitesApp({ api, color }: { api: Cliente; color: string }) {
  const [hijos, setHijos] = useState<Hijo[]>([]);
  const [tipos, setTipos] = useState<Tipo[]>([]);
  const [lista, setLista] = useState<Tramite[]>([]);
  const [abierto, setAbierto] = useState(false);
  const [tipo, setTipo] = useState<Tipo | null>(null);
  const [alumno, setAlumno] = useState<number | null>(null);
  const [fotos, setFotos] = useState<Record<number, Foto>>({});
  const [comentario, setComentario] = useState('');
  const [respondiendo, setRespondiendo] = useState<{ id: number; texto: string; foto: Foto | null } | null>(null);
  const [enviando, setEnviando] = useState(false);
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const cargar = useCallback(() => {
    api.pedir<{ data: Tramite[] }>('/familia/tramites').then((r) => setLista(r.data)).catch(() => undefined);
  }, [api]);
  useEffect(() => {
    api.pedir<{ data: Tipo[] }>('/familia/tramites/tipos').then((r) => setTipos(r.data)).catch(() => setTipos([]));
    api.pedir<{ hijos: { alumno_id?: number; id?: number; nombres: string; apellido_paterno?: string }[] }>('/me/familia')
      .then((r) => {
        const h = (r.hijos ?? []).map((x) => ({ id: Number(x.alumno_id ?? x.id), nombre: `${x.nombres} ${x.apellido_paterno ?? ''}`.trim() }));
        setHijos(h);
        setAlumno((a) => a ?? h[0]?.id ?? null);
      })
      .catch(() => setHijos([]));
    cargar();
  }, [api, cargar]);
  if (!hijos.length || (!tipos.length && !lista.length)) return null;

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
  const faltan = requisitosFaltantes(tipo ?? undefined, fotos);

  const enviar = () => ejecutar(async () => {
    if (!tipo || !alumno) return;
    const f = new FormData();
    f.append('tramite_tipo_id', String(tipo.id));
    f.append('alumno_id', String(alumno));
    if (comentario.trim()) f.append('comentario', comentario.trim());
    Object.entries(fotos).forEach(([req, foto]) => f.append(`adjuntos[${req}]`, foto as unknown as Blob));
    const r = await api.pedir<Tramite>('/familia/tramites', { metodo: 'POST', cuerpo: f });
    setMensaje(`Trámite ${r.numero} enviado.${r.cargo_monto ? ` Paga ${soles(r.cargo_monto)} en Mis pagos para que se atienda.` : ''}`);
    setAbierto(false);
    setTipo(null);
    setFotos({});
    setComentario('');
    cargar();
  });

  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Trámites</Text>
      {!abierto ? (
        tipos.length > 0 && <Boton titulo="Pedir un trámite" color={color} alPulsar={() => { setAbierto(true); setMensaje(null); }} />
      ) : (
        <>
          <Text style={estilos.subtitulo}>¿Qué necesitas?</Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
            {tipos.map((t) => (
              <Pressable key={t.id} accessibilityRole="button" accessibilityState={{ selected: tipo?.id === t.id }} onPress={() => { setTipo(t); setFotos({}); }}
                style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 8, borderWidth: 1, borderColor: color, backgroundColor: tipo?.id === t.id ? color : 'transparent' }}>
                <Text style={{ color: tipo?.id === t.id ? colores.blanco : color }}>{t.nombre} · {t.costo ? soles(t.costo) : 'gratis'}</Text>
              </Pressable>
            ))}
          </View>
          {hijos.length > 1 && (
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
              {hijos.map((h) => (
                <Pressable key={h.id} accessibilityRole="button" accessibilityState={{ selected: h.id === alumno }} onPress={() => setAlumno(h.id)} style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 8, borderWidth: 1, borderColor: color, backgroundColor: h.id === alumno ? color : 'transparent' }}>
                  <Text style={{ color: h.id === alumno ? colores.blanco : color }}>{h.nombre}</Text>
                </Pressable>
              ))}
            </View>
          )}
          {tipo && (
            <>
              <Text style={estilos.subtitulo}>{tipo.descripcion ? `${tipo.descripcion} ` : ''}Plazo: {tipo.plazo_dias} día(s) hábil(es).{tipo.requiere_sin_deuda ? ' Exige estar al día en los pagos.' : ''}</Text>
              {tipo.requisitos.map((r) => (
                <View key={r.id} style={[estilos.fila, { flexDirection: 'column', alignItems: 'stretch', gap: 6 }]}>
                  <Text style={{ color: colores.texto }}>{r.descripcion}{r.obligatorio ? '' : ' (opcional)'}{fotos[r.id] ? ' ✓' : ''}</Text>
                  <View style={{ flexDirection: 'row', gap: 8 }}>
                    <View style={{ flex: 1 }}><Boton titulo="Tomar foto" borde color={color} alPulsar={() => ejecutar(async () => { const f = await tomarFoto(true); if (f) setFotos((x) => ({ ...x, [r.id]: f })); })} /></View>
                    <View style={{ flex: 1 }}><Boton titulo="Galería" borde color={color} alPulsar={() => ejecutar(async () => { const f = await tomarFoto(false); if (f) setFotos((x) => ({ ...x, [r.id]: f })); })} /></View>
                  </View>
                </View>
              ))}
              <Campo etiqueta="Comentario (opcional)" value={comentario} onChangeText={setComentario} multiline />
              {faltan.length > 0 && <Text style={estilos.subtitulo}>Falta: {faltan.join(', ')}.</Text>}
              <Boton titulo="Enviar trámite" color={color} cargando={enviando} deshabilitado={faltan.length > 0 || !alumno} alPulsar={enviar} />
            </>
          )}
          <Boton titulo="Cancelar" borde color={colores.suave} alPulsar={() => setAbierto(false)} />
        </>
      )}
      {mensaje && <Text style={estilos.subtitulo}>{mensaje}</Text>}
      <MensajeError>{error}</MensajeError>
      {lista.slice(0, 6).map((t) => (
        <View key={t.id} style={[estilos.fila, { flexDirection: 'column', alignItems: 'flex-start', gap: 4 }]}>
          <Text style={{ color: colores.texto, fontWeight: '600' }}>{t.tipo} · {t.alumno}</Text>
          <Text style={{ color: ESTADO_TRAMITE[t.estado].color }}>{t.numero} · {t.anulado ? 'Documento anulado' : ESTADO_TRAMITE[t.estado].texto}</Text>
          {t.cargo_monto !== null && !t.pagado && t.estado !== 'rechazado' && <Text style={estilos.subtitulo}>Pendiente de pago: {soles(t.cargo_monto)} (en Mis pagos).</Text>}
          {t.estado === 'entregado' && !t.anulado && (
            <Boton titulo="Descargar documento" color={color} alPulsar={() => ejecutar(async () => { await Linking.openURL((await api.pedir<{ url: string }>(`/familia/tramites/${t.id}/documento`)).url); })} />
          )}
          {t.estado === 'observado' && (respondiendo?.id === t.id ? (
            <View style={{ alignSelf: 'stretch', gap: 6 }}>
              <Campo etiqueta="Tu respuesta" value={respondiendo.texto} onChangeText={(texto) => setRespondiendo({ ...respondiendo, texto })} multiline />
              <Boton titulo={respondiendo.foto ? 'Foto adjunta ✓' : 'Adjuntar foto corregida'} borde color={color} alPulsar={() => ejecutar(async () => { const f = await tomarFoto(true); if (f) setRespondiendo((x) => (x ? { ...x, foto: f } : x)); })} />
              <Boton titulo="Enviar respuesta" color={color} cargando={enviando} deshabilitado={!respondiendo.texto.trim() && !respondiendo.foto} alPulsar={() => ejecutar(async () => {
                const f = new FormData();
                if (respondiendo.texto.trim()) f.append('comentario', respondiendo.texto.trim());
                if (respondiendo.foto) f.append('adjuntos[0]', respondiendo.foto as unknown as Blob);
                await api.pedir(`/familia/tramites/${t.id}/respuesta`, { metodo: 'POST', cuerpo: f });
                setRespondiendo(null);
                setMensaje('Respuesta enviada: el colegio vuelve a revisar tu trámite.');
                cargar();
              })} />
            </View>
          ) : <Boton titulo="Responder la observación" borde color={color} alPulsar={() => setRespondiendo({ id: t.id, texto: '', foto: null })} />)}
        </View>
      ))}
    </View>
  );
}
