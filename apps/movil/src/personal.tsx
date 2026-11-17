import { useCallback, useEffect, useRef, useState } from 'react';
import { Linking, Pressable, Switch, Text, View } from 'react-native';
import * as Location from 'expo-location';
import type { Cliente } from './api';
import { CameraView, useCameraPermissions } from 'expo-camera';
import * as Crypto from 'expo-crypto';
import { guardarCifrado, guardarOperacion, leerCifrado, useEstadoCola } from './colaDispositivo';
import { buscarEnRuta } from './recorridoLogica';
import { CLAVE_RUTAS } from './clavesLocales';
import { Boton, Campo, colores, estilos, MensajeError } from './ui';
import { detenerSeguimiento, guardarPosiciones, iniciarSeguimiento, recorridoEnCurso, SEGUNDOS_POSICION } from './ubicacion';

type Ruta = { id: number; nombre: string; placa: string | null; recorridos: { id: number; sentido: string; estado: string }[]; alumnos: { asignacion_id: number; nombres: string; apellido_paterno: string; paradero: string; codigo_carne?: string | null; documento_hash?: string | null }[] };
type Cuota = { numero: number; vencimiento: string; monto: number; pagado: number; estado: string };
type Convenio = { id: number; numero: string; estado: string; monto_total: number; pagado: number; saldo: number; firmado_at: string | null; cuotas: Cuota[] };

const soles = (n: number) => `S/ ${n.toFixed(2)}`;

/**
 * EDUN-199 / EDUN-163 / EDUN-165 · Conductor y auxiliar de movilidad: inicia el recorrido, comparte
 * la ubicación solo mientras está en curso (también en segundo plano) y marca quién subió o bajó.
 * Las marcas, la ubicación y el fin se guardan cifrados y se envían solos: funciona sin señal.
 */
export function RecorridoApp({ api, color }: { api: Cliente; color: string }) {
  const [rutas, setRutas] = useState<Ruta[] | null>(null);
  const [activo, setActivo] = useState<{ ruta: Ruta; recorrido: number; sentido: 'ida' | 'vuelta'; modo: 'segundo_plano' | 'primer_plano' } | null>(null);
  const [marcados, setMarcados] = useState<Record<number, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [iniciando, setIniciando] = useState(false);
  const [escaner, setEscaner] = useState(false);
  const [permisoCamara, pedirCamara] = useCameraPermissions();
  const [ultimoQr, setUltimoQr] = useState<string | null>(null);
  const [aviso, setAviso] = useState<string | null>(null);
  const reloj = useRef<ReturnType<typeof setInterval> | null>(null);
  const red = useEstadoCola();
  const parar = () => {
    if (reloj.current) clearInterval(reloj.current);
    reloj.current = null;
  };
  // Sin permiso «siempre»: se toma la ubicación cada 20 s con la app abierta.
  const enPrimerPlano = (recorrido: number) => {
    parar();
    const tomar = () => Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }).then((l) => guardarPosiciones(recorrido, [l])).catch(() => undefined);
    void tomar();
    reloj.current = setInterval(tomar, SEGUNDOS_POSICION * 1000);
  };
  const cargar = useCallback(async () => {
    try {
      // EDUN-213 · La ruta se guarda cifrada al iniciar el turno: sin señal, se usa la guardada.
      let lista: Ruta[];
      try {
        lista = (await api.pedir<{ data: Ruta[] }>('/movilidad/mis-rutas')).data;
        await guardarCifrado(CLAVE_RUTAS, lista);
      } catch (e) {
        const guardada = await leerCifrado<Ruta[]>(CLAVE_RUTAS);
        if (!guardada) throw e;
        lista = guardada;
      }
      setRutas(lista);
      // La app se cerró con un recorrido en curso: se retoma donde quedó.
      const guardado = await recorridoEnCurso();
      const ruta = lista.find((r) => r.recorridos.some((x) => x.id === guardado && x.estado === 'en_curso'));
      const rec = ruta?.recorridos.find((x) => x.id === guardado);
      if (ruta && rec) {
        const modo = await iniciarSeguimiento(rec.id);
        setActivo({ ruta, recorrido: rec.id, sentido: rec.sentido as 'ida' | 'vuelta', modo });
        if (modo === 'primer_plano') enPrimerPlano(rec.id);
      }
    } catch {
      setRutas((r) => r ?? []);
    }
  }, [api]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    void cargar();
    return parar;
  }, [cargar]);
  if (!rutas?.length) return null;

  const iniciar = async (ruta: Ruta, sentido: 'ida' | 'vuelta') => {
    setError(null);
    if (!red.enLinea) return setError('Para iniciar el recorrido necesitas señal. Después puedes seguir sin conexión.');
    const permiso = await Location.requestForegroundPermissionsAsync();
    if (permiso.status !== 'granted') return setError('Da permiso de ubicación para compartir el recorrido con las familias.');
    setIniciando(true);
    try {
      const r = await api.pedir<{ recorrido_id: number }>(`/movilidad/rutas/${ruta.id}/recorridos`, { metodo: 'POST', cuerpo: { sentido } });
      const modo = await iniciarSeguimiento(r.recorrido_id);
      setActivo({ ruta, recorrido: r.recorrido_id, sentido, modo });
      setMarcados({});
      if (modo === 'primer_plano') enPrimerPlano(r.recorrido_id);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo iniciar.');
    } finally {
      setIniciando(false);
    }
  };
  const finalizar = async () => {
    if (!activo) return;
    parar();
    await detenerSeguimiento();
    await guardarOperacion('mov_fin', { recorrido_id: activo.recorrido, finalizado_at: new Date().toISOString() });
    setActivo(null);
    void cargar();
  };
  const marcar = async (asignacion: number, tipo: 'subio' | 'bajo' | 'no_subio') => {
    setMarcados({ ...marcados, [asignacion]: tipo });
    try {
      await guardarOperacion('mov_evento', { recorrido_id: activo!.recorrido, asignacion_id: asignacion, tipo, ocurrido_at: new Date().toISOString() });
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo guardar la marca.');
    }
  };

  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Movilidad escolar</Text>
      {!activo ? rutas.map((r) => (
        <View key={r.id} style={{ gap: 6 }}>
          <Text style={{ color: colores.texto, fontWeight: '600' }}>{r.nombre}{r.placa ? ` · ${r.placa}` : ''} · {r.alumnos.length} alumnos</Text>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><Boton titulo="Iniciar ida" color={color} deshabilitado={iniciando} alPulsar={() => iniciar(r, 'ida')} /></View>
            <View style={{ flex: 1 }}><Boton titulo="Iniciar vuelta" borde color={color} deshabilitado={iniciando} alPulsar={() => iniciar(r, 'vuelta')} /></View>
          </View>
        </View>
      )) : (
        <>
          <Text style={{ color: '#15803d' }}>
            En curso: {activo.ruta.nombre}. {activo.modo === 'segundo_plano' ? 'La ubicación se comparte aunque bloquees el teléfono.' : 'La ubicación se comparte con la app abierta (da permiso «siempre» para seguir con la pantalla apagada).'}
          </Text>
          {!red.enLinea && <Text style={estilos.subtitulo}>Sin señal: las marcas y la ubicación se guardan y se envían al volver la conexión.</Text>}
          {activo.ruta.alumnos.map((a) => (
            <View key={a.asignacion_id} style={estilos.fila}>
              <Text style={{ flex: 1, color: colores.texto }}>{a.nombres} {a.apellido_paterno}{'\n'}<Text style={{ color: colores.suave }}>{a.paradero}</Text></Text>
              {marcados[a.asignacion_id] ? <Text style={{ color: colores.suave }}>{marcados[a.asignacion_id].replace('_', ' ')}</Text> : (
                <View style={{ flexDirection: 'row', gap: 6 }}>
                  <Pressable accessibilityRole="button" accessibilityLabel={`${a.nombres}: ${activo.sentido === 'vuelta' ? 'bajó' : 'subió'}`} onPress={() => marcar(a.asignacion_id, activo.sentido === 'vuelta' ? 'bajo' : 'subio')} style={{ padding: 8, borderRadius: 8, backgroundColor: color }}><Text style={{ color: '#fff' }}>✓</Text></Pressable>
                  <Pressable accessibilityRole="button" accessibilityLabel={`${a.nombres}: no subió`} onPress={() => marcar(a.asignacion_id, 'no_subio')} style={{ padding: 8, borderRadius: 8, borderWidth: 1, borderColor: colores.error }}><Text style={{ color: colores.error }}>✕</Text></Pressable>
                </View>
              )}
            </View>
          ))}
          {/* EDUN-213 · Marca con el QR del carné, también sin señal: solo alumnos de esta ruta. */}
          {!escaner ? <Boton titulo="Marcar con el QR del carné" borde color={color} alPulsar={async () => { if (!permisoCamara?.granted) await pedirCamara(); setEscaner(true); }} /> : permisoCamara?.granted ? (
            <>
              <CameraView style={{ height: 220, borderRadius: 12 }} barcodeScannerSettings={{ barcodeTypes: ['qr'] }} onBarcodeScanned={async ({ data }) => {
                if (data === ultimoQr) return;
                setUltimoQr(data);
                setTimeout(() => setUltimoQr(null), 2500);
                const hash = await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, data.trim().toUpperCase());
                const a = buscarEnRuta(activo.ruta.alumnos, data, hash);
                if (!a) { setAviso('Ese carné no es de un alumno de esta ruta.'); return; }
                if (marcados[a.asignacion_id]) { setAviso(`${a.nombres} ya está marcado.`); return; }
                setAviso(`${a.nombres} ${a.apellido_paterno}: ${activo.sentido === 'vuelta' ? 'bajó' : 'subió'}.`);
                void marcar(a.asignacion_id, activo.sentido === 'vuelta' ? 'bajo' : 'subio');
              }} />
              <Boton titulo="Cerrar escáner" borde color={color} alPulsar={() => setEscaner(false)} />
            </>
          ) : <Text style={estilos.subtitulo}>Da permiso de cámara para escanear los carnés.</Text>}
          {aviso && <Text style={{ color: colores.texto, fontWeight: '600' }}>{aviso}</Text>}
          <Boton titulo="Finalizar recorrido" borde color={colores.error} alPulsar={finalizar} />
        </>
      )}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}

type Recoge = { id: number | null; nombres: string; numero_documento: string; parentesco: string; apoderado: boolean };

/**
 * EDUN-199 / EDUN-176 · Enfermería: atención rápida en tópico con aviso al padre. Si el alumno se
 * va (derivado), se elige quién lo retira entre sus apoderados y autorizados, y queda la salida.
 */
export function EnfermeriaApp({ api, color }: { api: Cliente; color: string }) {
  const [q, setQ] = useState('');
  const [resultados, setResultados] = useState<{ id: number; nombres: string; apellido_paterno: string }[]>([]);
  const [alumno, setAlumno] = useState<{ id: number; nombres: string; apellido_paterno: string } | null>(null);
  const [motivo, setMotivo] = useState('');
  const [accion, setAccion] = useState('');
  const [derivado, setDerivado] = useState(false);
  const [recogen, setRecogen] = useState<Recoge[] | null>(null);
  const [retira, setRetira] = useState<string | null>(null);
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const red = useEstadoCola();
  const [codigo, setCodigo] = useState('');
  const buscar = async () => {
    try {
      setResultados((await api.pedir<{ data: { id: number; nombres: string; apellido_paterno: string }[] }>(`/salud/alumnos?q=${encodeURIComponent(q.trim())}`)).data);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo buscar.');
    }
  };
  const cambiarDerivado = async (v: boolean) => {
    setDerivado(v);
    setRetira(null);
    if (v && alumno && !recogen) {
      try {
        setRecogen((await api.pedir<{ data: Recoge[] }>(`/salud/alumnos/${alumno.id}/pueden-recoger`)).data);
      } catch (e) {
        setError(e instanceof Error ? e.message : 'No se pudo cargar quién puede retirarlo.');
      }
    }
  };
  const limpiar = () => {
    setAlumno(null); setMotivo(''); setAccion(''); setDerivado(false); setQ(''); setResultados([]); setRecogen(null); setRetira(null);
  };
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Tópico: atención rápida</Text>
      {!red.enLinea && !alumno ? (
        // EDUN-216 · Sin señal: solo alumno (carné o DNI), síntoma y acción; se envía al volver la conexión.
        <>
          <Text style={estilos.subtitulo}>Sin conexión: registra lo básico. La ficha de salud solo se ve con señal; la familia recibe el aviso al sincronizar.</Text>
          <Campo etiqueta="Carné o DNI del alumno" value={codigo} onChangeText={(t) => setCodigo(t.replace(/\s/g, '').toUpperCase())} autoCapitalize="characters" />
          <Campo etiqueta="Síntoma o motivo" value={motivo} onChangeText={setMotivo} />
          <Campo etiqueta="Qué se hizo" value={accion} onChangeText={setAccion} multiline />
          <Boton titulo="Guardar sin conexión" color={color} deshabilitado={codigo.length < 8 || !motivo.trim() || !accion.trim()} alPulsar={async () => {
            await guardarOperacion('atencion', { codigo, motivo: motivo.trim(), accion: accion.trim(), atendido_at: new Date().toISOString() });
            setMensaje('Atención guardada: se enviará y se avisará a la familia al volver la señal.');
            setCodigo(''); setMotivo(''); setAccion('');
          }} />
        </>
      ) : !alumno ? (
        <>
          <Campo etiqueta="Alumno (nombre, DNI o código del carné)" value={q} onChangeText={setQ} onSubmitEditing={buscar} />
          <Boton titulo="Buscar" borde color={color} deshabilitado={q.trim().length < 3} alPulsar={buscar} />
          {resultados.map((a) => <Pressable key={a.id} accessibilityRole="button" onPress={() => setAlumno(a)} style={estilos.fila}><Text style={{ color: colores.texto }}>{a.apellido_paterno}, {a.nombres}</Text></Pressable>)}
        </>
      ) : (
        <>
          <Text style={{ color: colores.texto, fontWeight: '600' }}>{alumno.nombres} {alumno.apellido_paterno}</Text>
          <Campo etiqueta="Motivo" value={motivo} onChangeText={setMotivo} />
          <Campo etiqueta="Qué se hizo" value={accion} onChangeText={setAccion} multiline />
          <View style={estilos.fila}><Text style={{ flex: 1, color: colores.texto }}>Derivado (requiere que lo recojan)</Text><Switch accessibilityLabel="Derivado" value={derivado} trackColor={{ true: color }} onValueChange={cambiarDerivado} /></View>
          {derivado && recogen && (
            <View style={{ gap: 6 }}>
              <Text style={estilos.subtitulo}>¿Quién lo retira ahora? Verifica su documento. Si lo recogen más tarde, registra la salida en portería.</Text>
              {recogen.length === 0 && <Text style={{ color: colores.error }}>No tiene apoderados ni autorizados para recogerlo: comunícate con la familia.</Text>}
              {recogen.map((p) => (
                <Pressable key={p.numero_documento} accessibilityRole="radio" accessibilityState={{ selected: retira === p.numero_documento }} onPress={() => setRetira(retira === p.numero_documento ? null : p.numero_documento)}
                  style={[estilos.fila, { borderWidth: 1, borderRadius: 8, padding: 8, borderColor: retira === p.numero_documento ? color : colores.borde }]}>
                  <Text style={{ flex: 1, color: colores.texto }}>{p.nombres} · {p.parentesco}{'\n'}<Text style={{ color: colores.suave }}>Documento {p.numero_documento}</Text></Text>
                  {retira === p.numero_documento && <Text style={{ color }}>✓</Text>}
                </Pressable>
              ))}
            </View>
          )}
          <Boton titulo={retira ? 'Registrar, entregar y avisar' : 'Registrar y avisar a la familia'} color={color} deshabilitado={!motivo.trim() || !accion.trim()} alPulsar={async () => {
            setError(null);
            try {
              await api.pedir('/salud/atenciones', { metodo: 'POST', cuerpo: { alumno_id: alumno.id, motivo, accion, derivado, avisar_padre: true, retiro_documento: derivado ? retira : null } });
              const quien = recogen?.find((p) => p.numero_documento === retira);
              setMensaje(`Atención de ${alumno.nombres} registrada${quien ? `; salió con ${quien.nombres}` : ''}. La familia fue avisada.`);
              limpiar();
            } catch (e) {
              setError(e instanceof Error ? e.message : 'No se pudo registrar.');
            }
          }} />
          <Boton titulo="Cancelar" borde color={colores.suave} alPulsar={limpiar} />
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
