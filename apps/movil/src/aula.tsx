import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, Text, TextInput, View } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as ImagePicker from 'expo-image-picker';
import type { Cliente } from './api';
import { ACCESIBILIDAD_INICIAL, coloresAula, faltanRespuestas, leerAccesibilidad, plazo, siguienteEscala, type Accesibilidad } from './aulaLogica';
import { Boton, estilos, MensajeError } from './ui';

type Recurso = { id: number; tipo: string; titulo: string; url: string | null; contenido: string | null; archivo_id: number | null; completado: boolean; bloqueado: boolean; porcentaje: number };
type Tarea = { id: number; titulo: string; instrucciones: string; vence_at: string; acepta_tardias: boolean; entrega: { nota: string | null; tardia: boolean; comentario: string | null } | null };
type Cuest = { id: number; titulo: string; minutos: number | null; bloqueado: boolean };
type Unidad = { id: number; titulo: string; recursos: Recurso[]; tareas: Tarea[]; cuestionarios: Cuest[]; encuesta: { respondida: boolean; disponible: boolean } | null };
type Curso = { id: number; titulo: string; area: string; docente: string; avance: number | null; continuar: { recurso_id: number; titulo: string } | null; unidades: Unidad[]; video_pct_completado: number };
type CursoLista = { id: number; area: string; docente: string; pendientes: number };
type Pregunta = { id: number; tipo: string; enunciado: string; opciones: unknown };
type TareaHijo = { id: number; titulo: string; curso: string; vence_at: string; alumno: string; estado: string; nota: string | null };

const CLAVE_ACCESIBILIDAD = 'edunova.aula.accesibilidad';

function useAccesibilidad(): [Accesibilidad, (a: Accesibilidad) => void] {
  const [a, setA] = useState<Accesibilidad>(ACCESIBILIDAD_INICIAL);
  useEffect(() => {
    AsyncStorage.getItem(CLAVE_ACCESIBILIDAD).then((t) => setA(leerAccesibilidad(t))).catch(() => undefined);
  }, []);
  return [a, (n) => { setA(n); void AsyncStorage.setItem(CLAVE_ACCESIBILIDAD, JSON.stringify(n)).catch(() => undefined); }];
}

/**
 * EDUN-126 / 130 / 131 / 135 / 139 / 142 · El aula del alumno en la app: cursos, avance en orden,
 * entrega de tareas con foto, cuestionarios, foros y encuesta, con letra grande y alto contraste.
 */
export function AulaApp({ api, color }: { api: Cliente; color: string }) {
  const [cursos, setCursos] = useState<CursoLista[]>([]);
  const [curso, setCurso] = useState<Curso | null>(null);
  const [acc, setAcc] = useAccesibilidad();
  const [error, setError] = useState<string | null>(null);
  const [cargando, setCargando] = useState(false);
  const [lectura, setLectura] = useState<Recurso | null>(null);
  const [tarea, setTarea] = useState<Tarea | null>(null);
  const [cuest, setCuest] = useState<{ q: Cuest; preguntas: Pregunta[]; respuestas: Record<number, unknown>; resultado: string | null } | null>(null);
  const c = coloresAula(acc, color);
  const t = (base: number) => ({ fontSize: base * acc.escala, color: c.texto });

  useEffect(() => {
    api.pedir<{ data: CursoLista[] }>('/aula/cursos').then((r) => setCursos(r.data)).catch(() => setCursos([]));
  }, [api]);
  const abrir = useCallback((id: number) => {
    setError(null);
    api.pedir<Curso>(`/aula/cursos/${id}`).then(setCurso).catch((e) => setError(e instanceof Error ? e.message : 'No se pudo abrir el curso.'));
  }, [api]);
  if (!cursos.length) return null;
  const ejecutar = async (fn: () => Promise<void>) => {
    setCargando(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo completar.');
    } finally {
      setCargando(false);
    }
  };
  const avanzar = (r: Recurso) => api.pedir(`/aula/cursos/${curso!.id}/recursos/${r.id}/avance`, { metodo: 'POST', cuerpo: { porcentaje: 100 } });
  const abrirRecurso = (r: Recurso) => ejecutar(async () => {
    if (r.bloqueado) throw new Error('Completa primero el contenido anterior.');
    if (r.tipo === 'texto') { setLectura(r); await avanzar(r); return; }
    const url = r.archivo_id ? (await api.pedir<{ url: string }>(`/aula/cursos/${curso!.id}/recursos/${r.id}/archivo`)).url : r.url;
    if (url) await Linking.openURL(url);
    // Los videos subidos registran el porcentaje visto en la web; los demás se completan al abrirlos.
    if (r.tipo !== 'video' || !r.archivo_id) await avanzar(r);
    abrir(curso!.id);
  });

  const accesibilidad = (
    <View style={{ flexDirection: 'row', gap: 8 }}>
      <View style={{ flex: 1 }}><Boton titulo={`Letra ${acc.escala === 1 ? 'normal' : acc.escala === 1.25 ? 'grande' : 'muy grande'}`} borde color={c.acento} alPulsar={() => setAcc({ ...acc, escala: siguienteEscala(acc.escala) })} /></View>
      <View style={{ flex: 1 }}><Boton titulo={acc.contraste ? 'Contraste normal' : 'Alto contraste'} borde color={c.acento} alPulsar={() => setAcc({ ...acc, contraste: !acc.contraste })} /></View>
    </View>
  );

  if (!curso) {
    return (
      <View style={[estilos.tarjeta, { backgroundColor: c.fondo }]}>
        <Text style={[estilos.etiqueta, t(14)]}>Aula virtual</Text>
        {accesibilidad}
        {cursos.map((x) => (
          <Pressable key={x.id} accessibilityRole="button" onPress={() => abrir(x.id)} style={[estilos.fila, { borderBottomWidth: 1, borderColor: '#e5e7eb' }]}>
            <Text style={[t(16), { flex: 1 }]}>{x.area}{'\n'}<Text style={{ color: c.suave, fontSize: 13 * acc.escala }}>{x.docente}</Text></Text>
            {x.pendientes > 0 && <Text style={{ color: c.acento, fontWeight: '700', fontSize: 14 * acc.escala }}>{x.pendientes} pendiente(s)</Text>}
          </Pressable>
        ))}
        <MensajeError>{error}</MensajeError>
      </View>
    );
  }

  if (cuest) {
    const faltan = faltanRespuestas(cuest.preguntas, cuest.respuestas);
    const fijar = (id: number, v: unknown) => setCuest({ ...cuest, respuestas: { ...cuest.respuestas, [id]: v } });
    return (
      <View style={[estilos.tarjeta, { backgroundColor: c.fondo }]}>
        <Text style={[estilos.etiqueta, t(16)]}>{cuest.q.titulo}</Text>
        {cuest.resultado ? <Text style={t(16)}>{cuest.resultado}</Text> : cuest.preguntas.map((p, i) => (
          <View key={p.id} style={{ gap: 6, paddingVertical: 6 }}>
            <Text style={[t(15), { fontWeight: '600' }]}>{i + 1}. {p.enunciado}</Text>
            {p.tipo === 'opcion_multiple' && (p.opciones as string[]).map((o, j) => (
              <Pressable key={j} accessibilityRole="radio" accessibilityState={{ checked: cuest.respuestas[p.id] === j }} onPress={() => fijar(p.id, j)} style={{ padding: 10, borderRadius: 8, borderWidth: 1, borderColor: c.acento, backgroundColor: cuest.respuestas[p.id] === j ? c.acento : 'transparent' }}>
                <Text style={{ fontSize: 15 * acc.escala, color: cuest.respuestas[p.id] === j ? '#fff' : c.texto }}>{o}</Text>
              </Pressable>
            ))}
            {p.tipo === 'verdadero_falso' && [true, false].map((v) => (
              <Pressable key={String(v)} accessibilityRole="radio" accessibilityState={{ checked: cuest.respuestas[p.id] === v }} onPress={() => fijar(p.id, v)} style={{ padding: 10, borderRadius: 8, borderWidth: 1, borderColor: c.acento, backgroundColor: cuest.respuestas[p.id] === v ? c.acento : 'transparent' }}>
                <Text style={{ fontSize: 15 * acc.escala, color: cuest.respuestas[p.id] === v ? '#fff' : c.texto }}>{v ? 'Verdadero' : 'Falso'}</Text>
              </Pressable>
            ))}
            {p.tipo === 'respuesta_corta' && <TextInput accessibilityLabel="Tu respuesta" style={[estilos.campo, { fontSize: 16 * acc.escala }]} value={String(cuest.respuestas[p.id] ?? '')} onChangeText={(v) => fijar(p.id, v)} />}
            {p.tipo === 'relacionar' && (() => {
              const o = p.opciones as { izquierda: string[]; derecha: string[] };
              const actual = (cuest.respuestas[p.id] ?? {}) as Record<number, number>;
              return o.izquierda.map((izq, k) => (
                <View key={k} style={{ gap: 4 }}>
                  <Text style={t(14)}>{izq} →</Text>
                  <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 4 }}>{o.derecha.map((d, m) => (
                    <Pressable key={m} accessibilityRole="radio" accessibilityState={{ checked: actual[k] === m }} onPress={() => fijar(p.id, { ...actual, [k]: m })} style={{ padding: 6, borderRadius: 6, borderWidth: 1, borderColor: c.acento, backgroundColor: actual[k] === m ? c.acento : 'transparent' }}>
                      <Text style={{ fontSize: 13 * acc.escala, color: actual[k] === m ? '#fff' : c.texto }}>{d}</Text>
                    </Pressable>
                  ))}</View>
                </View>
              ));
            })()}
          </View>
        ))}
        {!cuest.resultado && <Boton titulo={faltan ? `Enviar (faltan ${faltan})` : 'Enviar respuestas'} color={c.acento} cargando={cargando} alPulsar={() => ejecutar(async () => {
          const r = await api.pedir<{ nota: string; porcentaje: number; intentos_disponibles: number }>(`/aula/cursos/${curso.id}/cuestionarios/${cuest.q.id}/envio`, { metodo: 'POST', cuerpo: { respuestas: cuest.respuestas } });
          setCuest({ ...cuest, resultado: `Nota ${r.nota} (${r.porcentaje} %). Te quedan ${r.intentos_disponibles} intento(s).` });
        })} />}
        <Boton titulo="Volver al curso" borde color={c.suave} alPulsar={() => { setCuest(null); abrir(curso.id); }} />
        <MensajeError>{error}</MensajeError>
      </View>
    );
  }

  return (
    <View style={[estilos.tarjeta, { backgroundColor: c.fondo }]}>
      <Text style={[estilos.etiqueta, t(17)]}>{curso.titulo}</Text>
      {accesibilidad}
      {curso.avance !== null && <Text style={t(15)}>Tu avance: {curso.avance} %{curso.continuar ? ` · sigue con «${curso.continuar.titulo}»` : ''}</Text>}
      {lectura && (
        <View style={{ gap: 8 }}>
          <Text style={[t(16), { fontWeight: '700' }]}>{lectura.titulo}</Text>
          <Text style={t(16)}>{lectura.contenido}</Text>
          <Boton titulo="Cerrar lectura" borde color={c.acento} alPulsar={() => { setLectura(null); abrir(curso.id); }} />
        </View>
      )}
      {!lectura && curso.unidades.map((u) => (
        <View key={u.id} style={{ gap: 6, paddingVertical: 6, borderTopWidth: 1, borderColor: '#e5e7eb' }}>
          <Text style={[t(16), { fontWeight: '700' }]}>{u.titulo}</Text>
          {u.recursos.map((r) => (
            <Pressable key={r.id} accessibilityRole="button" accessibilityState={{ disabled: r.bloqueado }} onPress={() => abrirRecurso(r)} style={{ paddingVertical: 6 }}>
              <Text style={[t(15), r.bloqueado && { color: c.suave }]}>{r.bloqueado ? '🔒 ' : r.completado ? '✓ ' : '• '}{r.titulo}</Text>
            </Pressable>
          ))}
          {u.tareas.map((x) => (
            <View key={x.id} style={{ gap: 4 }}>
              <Text style={t(15)}>Tarea: {x.titulo} · {plazo(x.vence_at)}{x.entrega ? (x.entrega.nota ? ` · nota ${x.entrega.nota}` : ' · entregada') : ''}</Text>
              {!x.entrega?.nota && <Boton titulo={x.entrega ? 'Volver a entregar' : 'Entregar'} borde color={c.acento} alPulsar={() => setTarea(x)} />}
            </View>
          ))}
          {u.cuestionarios.map((q) => (
            <Boton key={q.id} titulo={`${q.bloqueado ? '🔒 ' : ''}Cuestionario: ${q.titulo}`} borde color={c.acento} deshabilitado={q.bloqueado} cargando={cargando} alPulsar={() => ejecutar(async () => {
              const r = await api.pedir<{ preguntas: Pregunta[] }>(`/aula/cursos/${curso.id}/cuestionarios/${q.id}/intento`, { metodo: 'POST' });
              setCuest({ q, preguntas: r.preguntas, respuestas: {}, resultado: null });
            })} />
          ))}
          {u.encuesta?.disponible && !u.encuesta.respondida && (
            <View style={{ flexDirection: 'row', gap: 6, alignItems: 'center' }}>
              <Text style={t(14)}>¿Qué te pareció?</Text>
              {['😞', '😐', '🙂', '😄'].map((e, i) => (
                <Pressable key={e} accessibilityRole="button" accessibilityLabel={`${i + 1} de 4`} onPress={() => ejecutar(async () => { await api.pedir(`/aula/cursos/${curso.id}/unidades/${u.id}/encuesta`, { metodo: 'POST', cuerpo: { valor: i + 1 } }); abrir(curso.id); })}>
                  <Text style={{ fontSize: 26 * acc.escala }}>{e}</Text>
                </Pressable>
              ))}
            </View>
          )}
        </View>
      ))}
      {tarea && <EntregaTarea api={api} cursoId={curso.id} tarea={tarea} color={c.acento} escala={acc.escala} alTerminar={() => { setTarea(null); abrir(curso.id); }} />}
      <Boton titulo="Mis cursos" borde color={c.suave} alPulsar={() => setCurso(null)} />
      <MensajeError>{error}</MensajeError>
    </View>
  );
}

function EntregaTarea({ api, cursoId, tarea, color, escala, alTerminar }: { api: Cliente; cursoId: number; tarea: Tarea; color: string; escala: number; alTerminar: () => void }) {
  const [texto, setTexto] = useState('');
  const [foto, setFoto] = useState<ImagePicker.ImagePickerAsset | null>(null);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const elegir = async (camara: boolean) => {
    const permiso = camara ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permiso.granted) return setError('Da permiso para adjuntar la foto.');
    const r = camara ? await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 0.6 }) : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.6 });
    if (!r.canceled) setFoto(r.assets[0]);
  };
  return (
    <View style={{ gap: 8, paddingVertical: 8 }}>
      <Text style={{ fontSize: 15 * escala, fontWeight: '700' }}>{tarea.titulo}</Text>
      <Text style={{ fontSize: 15 * escala }}>{tarea.instrucciones}</Text>
      <TextInput accessibilityLabel="Tu respuesta" multiline style={[estilos.campo, { minHeight: 80, fontSize: 16 * escala }]} value={texto} onChangeText={setTexto} placeholder="Escribe tu respuesta (opcional si adjuntas foto)" />
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <View style={{ flex: 1 }}><Boton titulo="Tomar foto" borde color={color} alPulsar={() => elegir(true)} /></View>
        <View style={{ flex: 1 }}><Boton titulo="Galería" borde color={color} alPulsar={() => elegir(false)} /></View>
      </View>
      {foto && <Text style={{ fontSize: 14 * escala }}>Foto adjunta ✓</Text>}
      <Boton titulo="Entregar" color={color} cargando={enviando} deshabilitado={!texto.trim() && !foto} alPulsar={async () => {
        setEnviando(true);
        setError(null);
        try {
          const f = new FormData();
          if (texto.trim()) f.append('texto', texto.trim());
          if (foto) f.append('archivo', { uri: foto.uri, name: foto.fileName ?? 'tarea.jpg', type: foto.mimeType ?? 'image/jpeg' } as unknown as Blob);
          await api.pedir(`/aula/cursos/${cursoId}/tareas/${tarea.id}/entrega`, { metodo: 'POST', cuerpo: f });
          alTerminar();
        } catch (e) {
          setError(e instanceof Error ? e.message : 'No se pudo entregar.');
        } finally {
          setEnviando(false);
        }
      }} />
      <MensajeError>{error}</MensajeError>
    </View>
  );
}

const ESTADO_HIJO: Record<string, string> = { pendiente: 'Pendiente', entregada: 'Entregada', revisada: 'Revisada', vencida: 'No entregó' };

/** EDUN-134 · La familia ve en la app las tareas de sus hijos. */
export function TareasHijosApp({ api }: { api: Cliente }) {
  const [tareas, setTareas] = useState<TareaHijo[]>([]);
  useEffect(() => {
    api.pedir<{ data: TareaHijo[] }>('/aula/familia/tareas').then((r) => setTareas(r.data)).catch(() => setTareas([]));
  }, [api]);
  if (!tareas.length) return null;
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Tareas del aula</Text>
      {tareas.slice(0, 8).map((x) => (
        <Text key={`${x.id}-${x.alumno}`} style={estilos.subtitulo}>{x.alumno} · {x.curso}: {x.titulo} ({plazo(x.vence_at)}) — {x.estado === 'revisada' ? `nota ${x.nota}` : ESTADO_HIJO[x.estado] ?? x.estado}</Text>
      ))}
    </View>
  );
}
