import { useEffect, useMemo, useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import type { Cliente } from './api';
import { guardarCifrado, guardarOperacion, leerCifrado, useEstadoCola } from './colaDispositivo';
import { buscarEnLista, verificar, type AlumnoPorteria, type ListaTurno } from './porteriaLogica';
import { Boton, Campo, colores, estilos, MensajeError } from './ui';

const CLAVE_LISTA = 'edunova.porteria.lista';

/**
 * EDUN-214 · Portería: al inicio del turno se descarga (cifrada) la lista de quién puede recoger a
 * cada alumno; así se verifica el documento sin señal. Cada salida se guarda y se envía sola.
 */
export function PorteriaApp({ api, color }: { api: Cliente; color: string }) {
  const red = useEstadoCola();
  const [lista, setLista] = useState<ListaTurno | null>(null);
  const [q, setQ] = useState('');
  const [alumno, setAlumno] = useState<AlumnoPorteria | null>(null);
  const [documento, setDocumento] = useState('');
  const [resultado, setResultado] = useState<{ ok: boolean; texto: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [bajando, setBajando] = useState(false);
  useEffect(() => {
    leerCifrado<ListaTurno>(CLAVE_LISTA).then((l) => l && setLista(l)).catch(() => undefined);
  }, []);
  const encontrados = useMemo(() => (lista ? buscarEnLista(lista.alumnos, q) : []), [lista, q]);
  const descargar = async () => {
    setError(null);
    setBajando(true);
    try {
      const l = await api.pedir<ListaTurno>('/porteria/lista');
      await guardarCifrado(CLAVE_LISTA, l);
      setLista(l);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo descargar la lista.');
    } finally {
      setBajando(false);
    }
  };
  const registrar = async (a: AlumnoPorteria, doc: string) => {
    const persona = verificar(a, doc);
    if (!persona) return setResultado({ ok: false, texto: `NO AUTORIZADO: el documento ${doc.trim()} no puede recoger a ${a.nombre}. No lo dejes salir y avisa a la coordinación.` });
    try {
      await guardarOperacion('salida', { alumno_id: a.id, numero_documento: persona.numero_documento, salio_at: new Date().toISOString() });
      setResultado({ ok: true, texto: `${a.nombre} sale con ${persona.nombres} (${persona.parentesco}).${red.enLinea ? '' : ' Sin señal: se envía al volver la conexión.'}` });
      setAlumno(null); setDocumento(''); setQ('');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo registrar.');
    }
  };
  const hora = lista ? new Date(lista.generado_at).toTimeString().slice(0, 5) : null;
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Portería: salidas y recojo</Text>
      <Text style={estilos.subtitulo}>{lista ? `Lista del turno descargada a las ${hora} · ${lista.alumnos.length} alumnos.` : 'Descarga la lista al inicio del turno para verificar sin señal.'}</Text>
      <Boton titulo={lista ? 'Actualizar lista del turno' : 'Descargar lista del turno'} borde={!!lista} color={color} cargando={bajando} deshabilitado={!red.enLinea || bajando} alPulsar={descargar} />
      {lista && !alumno && (
        <>
          <Campo etiqueta="Alumno (nombre, DNI o código del carné)" value={q} onChangeText={(t) => { setQ(t); setResultado(null); }} autoCapitalize="characters" />
          {encontrados.map((a) => (
            <Pressable key={a.id} accessibilityRole="button" onPress={() => setAlumno(a)} style={estilos.fila}>
              <Text style={{ color: colores.texto }}>{a.nombre}{'\n'}<Text style={{ color: colores.suave }}>{a.seccion}</Text></Text>
            </Pressable>
          ))}
        </>
      )}
      {alumno && (
        <View style={{ gap: 6 }}>
          <Text style={{ color: colores.texto, fontWeight: '700' }}>{alumno.nombre} · {alumno.seccion}</Text>
          <Text style={estilos.subtitulo}>Pueden recogerlo (pide su documento):</Text>
          {alumno.pueden_recoger.length === 0 && <Text style={{ color: colores.error }}>Nadie registrado para recogerlo. No lo dejes salir sin autorización de la coordinación.</Text>}
          {alumno.pueden_recoger.map((p) => (
            <Pressable key={p.numero_documento} accessibilityRole="button" onPress={() => registrar(alumno, p.numero_documento)} style={[estilos.fila, { borderWidth: 1, borderColor: colores.borde, borderRadius: 8, padding: 8 }]}>
              <Text style={{ flex: 1, color: colores.texto }}>{p.nombres} · {p.parentesco}{'\n'}<Text style={{ color: colores.suave }}>Documento {p.numero_documento}</Text></Text>
              <Text style={{ color }}>Sale con esta persona</Text>
            </Pressable>
          ))}
          <Campo etiqueta="O escribe el documento de quien recoge" value={documento} onChangeText={setDocumento} keyboardType="default" autoCapitalize="characters" />
          <Boton titulo="Verificar y registrar salida" color={color} deshabilitado={documento.trim().length < 8} alPulsar={() => registrar(alumno, documento)} />
          <Boton titulo="Cancelar" borde color={colores.suave} alPulsar={() => { setAlumno(null); setDocumento(''); }} />
        </View>
      )}
      {resultado && <Text accessibilityLiveRegion="assertive" style={{ fontSize: 16, fontWeight: '700', color: resultado.ok ? '#15803d' : colores.error }}>{resultado.texto}</Text>}
      <MensajeError>{error}</MensajeError>
    </View>
  );
}
