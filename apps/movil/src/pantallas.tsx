import { useEffect, useState } from 'react';
import { Pressable, ScrollView, Switch, Text, View } from 'react-native';
import { Image } from 'expo-image';
import type { Cliente, Colegio, EstadoSesion } from './api';
import { colorDeMarca, iniciales, textoSobre } from './marca';
import { Boton, Campo, colores, estilos, MensajeError, Tarjeta } from './ui';

type Accion = (fn: () => Promise<void>) => Promise<void>;

/** Hook mínimo de envío con error legible. */
export function useEnvio(): { enviando: boolean; error: string | null; ejecutar: Accion } {
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return {
    enviando,
    error,
    ejecutar: async (fn) => {
      setEnviando(true);
      setError(null);
      try {
        await fn();
      } catch (e) {
        setError(e instanceof Error ? e.message : 'No se pudo completar la acción.');
      } finally {
        setEnviando(false);
      }
    },
  };
}

export function Ingreso({ alIngresar }: { alIngresar: (usuario: string, clave: string) => Promise<void> }) {
  const [usuario, setUsuario] = useState('');
  const [clave, setClave] = useState('');
  const envio = useEnvio();
  return (
    <ScrollView contentContainerStyle={[estilos.contenido, { paddingTop: 60 }]} keyboardShouldPersistTaps="handled">
      <Text style={estilos.titulo}>Edunova</Text>
      <Text style={estilos.subtitulo}>Ingresa con tu DNI o correo. Es la misma cuenta que usas en la web del colegio.</Text>
      <Tarjeta>
        <Campo etiqueta="DNI o correo" value={usuario} onChangeText={setUsuario} autoCapitalize="none" autoComplete="username" keyboardType="email-address" />
        <Campo etiqueta="Contraseña" value={clave} onChangeText={setClave} secureTextEntry autoComplete="password" />
        <MensajeError>{envio.error}</MensajeError>
        <Boton titulo="Ingresar" cargando={envio.enviando} deshabilitado={!usuario || !clave} alPulsar={() => envio.ejecutar(() => alIngresar(usuario.trim(), clave))} />
      </Tarjeta>
    </ScrollView>
  );
}

export function DosPasos({ alVerificar, alSalir }: { alVerificar: (codigo: string) => Promise<void>; alSalir: () => void }) {
  const [codigo, setCodigo] = useState('');
  const envio = useEnvio();
  return (
    <ScrollView contentContainerStyle={[estilos.contenido, { paddingTop: 60 }]} keyboardShouldPersistTaps="handled">
      <Text style={estilos.titulo}>Verificación en dos pasos</Text>
      <Text style={estilos.subtitulo}>Escribe el código de 6 dígitos de tu app de autenticación o uno de tus códigos de respaldo.</Text>
      <Tarjeta>
        <Campo etiqueta="Código" value={codigo} onChangeText={setCodigo} keyboardType="number-pad" autoComplete="one-time-code" maxLength={11} />
        <MensajeError>{envio.error}</MensajeError>
        <Boton titulo="Verificar" cargando={envio.enviando} deshabilitado={codigo.length < 6} alPulsar={() => envio.ejecutar(() => alVerificar(codigo.trim()))} />
        <Boton titulo="Salir" borde alPulsar={alSalir} />
      </Tarjeta>
    </ScrollView>
  );
}

export function Aviso({ titulo, texto, alSalir }: { titulo: string; texto: string; alSalir: () => void }) {
  return (
    <View style={[estilos.contenido, { paddingTop: 60 }]}>
      <Text style={estilos.titulo}>{titulo}</Text>
      <Text style={estilos.subtitulo}>{texto}</Text>
      <Boton titulo="Salir" borde alPulsar={alSalir} />
    </View>
  );
}

export function Escudo({ colegio, api, tamano = 44 }: { colegio: Colegio; api: Cliente; tamano?: number }) {
  const color = colorDeMarca(colegio.marca?.color_primario);
  const [falla, setFalla] = useState(false);
  if (colegio.marca?.tiene_logo && !falla) {
    return (
      <Image
        accessibilityLabel={`Logo de ${colegio.nombre}`}
        source={{ uri: `${api.base}/institucion/logo`, headers: { ...api.cabeceras(), 'X-Institucion': String(colegio.id) } }}
        style={{ width: tamano, height: tamano, borderRadius: 10, backgroundColor: colores.blanco }}
        contentFit="contain"
        onError={() => setFalla(true)}
      />
    );
  }
  return (
    <View style={{ width: tamano, height: tamano, borderRadius: 10, backgroundColor: color, alignItems: 'center', justifyContent: 'center' }}>
      <Text style={{ color: textoSobre(color), fontWeight: '700' }}>{iniciales(colegio.nombre)}</Text>
    </View>
  );
}

export function ElegirColegio({ estado, alElegir, alSalir }: { estado: EstadoSesion; alElegir: (id: number) => Promise<void>; alSalir: () => void }) {
  const envio = useEnvio();
  return (
    <ScrollView contentContainerStyle={[estilos.contenido, { paddingTop: 60 }]}>
      <Text style={estilos.titulo}>Hola, {estado.usuario.nombres}</Text>
      <Text style={estilos.subtitulo}>Elige el colegio con el que quieres entrar. Puedes cambiarlo después.</Text>
      {estado.colegios.map((c) => (
        <Pressable key={c.id} accessibilityRole="button" onPress={() => envio.ejecutar(() => alElegir(c.id))} style={({ pressed }) => [estilos.tarjeta, pressed && { opacity: 0.7 }]}>
          <Text style={{ fontSize: 17, fontWeight: '600', color: colores.texto }}>{c.nombre}</Text>
          <Text style={estilos.subtitulo}>{c.roles.join(', ')}</Text>
        </Pressable>
      ))}
      <MensajeError>{envio.error}</MensajeError>
      <Boton titulo="Salir" borde alPulsar={alSalir} />
    </ScrollView>
  );
}

type Preferencia = { tipo: string; nombre: string; push: boolean };

export function Preferencias({ api, color }: { api: Cliente; color: string }) {
  const [lista, setLista] = useState<Preferencia[] | null>(null);
  const envio = useEnvio();
  useEffect(() => {
    let vivo = true;
    api.pedir<{ data: Preferencia[] }>('/auth/notificaciones/preferencias').then((r) => vivo && setLista(r.data)).catch(() => vivo && setLista([]));
    return () => {
      vivo = false;
    };
  }, [api]);
  if (!lista) return <Text style={estilos.subtitulo}>Cargando…</Text>;
  return (
    <>
      {lista.map((p) => (
        <View key={p.tipo} style={estilos.fila}>
          <Text style={{ fontSize: 16, color: colores.texto, flex: 1 }}>{p.nombre}</Text>
          <Switch
            accessibilityLabel={`Avisos de ${p.nombre}`}
            value={p.push}
            trackColor={{ true: color }}
            onValueChange={(v) => envio.ejecutar(async () => setLista((await api.pedir<{ data: Preferencia[] }>('/auth/notificaciones/preferencias', { metodo: 'PUT', cuerpo: { preferencias: { [p.tipo]: v } } })).data))}
          />
        </View>
      ))}
      <MensajeError>{envio.error}</MensajeError>
    </>
  );
}
