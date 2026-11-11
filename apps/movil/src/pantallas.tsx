import { useEffect, useState } from 'react';
import { Pressable, ScrollView, Switch, Text, View } from 'react-native';
import { Image } from 'expo-image';
import type { Cliente, Colegio, EstadoSesion } from './api';
import { AsistenciaApp, NotasApp, PuertaQrApp } from './academico';
import { EstadoCuentaApp, RatificacionApp } from './familia';
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

function Escudo({ colegio, api, tamano = 44 }: { colegio: Colegio; api: Cliente; tamano?: number }) {
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

function Preferencias({ api, color }: { api: Cliente; color: string }) {
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

export function Inicio({ estado, api, aviso, biometria, alBiometria, alCambiarColegio, alSalir, enfocar }: {
  estado: EstadoSesion; api: Cliente; aviso: string | null; biometria: { disponible: boolean; activa: boolean };
  alBiometria: (v: boolean) => void; alCambiarColegio: () => void; alSalir: () => void; enfocar: 'inicio' | 'notificaciones' | 'preferencias';
}) {
  const colegio = estado.colegio_activo!;
  const color = colorDeMarca(colegio.marca?.color_primario);
  const texto = textoSobre(color);
  const envio = useEnvio();
  const [ok, setOk] = useState<string | null>(null);
  const [version, setVersion] = useState(0);

  return (
    <View style={estilos.pantalla}>
      <View style={{ backgroundColor: color, paddingTop: 56, paddingBottom: 20, paddingHorizontal: 20, flexDirection: 'row', alignItems: 'center', gap: 14 }}>
        <Escudo colegio={colegio} api={api} />
        <View style={{ flex: 1 }}>
          <Text style={{ color: texto, fontSize: 18, fontWeight: '700' }} numberOfLines={2}>{colegio.nombre}</Text>
          <Text style={{ color: texto, opacity: 0.85 }}>{estado.usuario.nombres} {estado.usuario.apellidos}</Text>
        </View>
      </View>
      <ScrollView contentContainerStyle={estilos.contenido}>
        {aviso && <Tarjeta><Text style={{ color: colores.texto }}>{aviso}</Text></Tarjeta>}
        {colegio.estado === 'suspendido' && <MensajeError>El acceso del colegio está pausado. Comunícate con el colegio.</MensajeError>}
        {colegio.roles.some((r) => ['docente', 'tutor', 'auxiliar', 'coordinador'].includes(r)) && <AsistenciaApp api={api} color={color} />}
        {colegio.roles.some((r) => ['auxiliar', 'porteria'].includes(r)) && <PuertaQrApp api={api} color={color} />}
        {colegio.roles.includes('apoderado') && <NotasApp api={api} color={color} />}
        {colegio.roles.includes('apoderado') && (
          <>
            <RatificacionApp api={api} color={color} alRatificar={() => setVersion((v) => v + 1)} />
            <Tarjeta>
              <Text style={estilos.etiqueta}>Mis pagos</Text>
              <EstadoCuentaApp key={version} api={api} color={color} />
            </Tarjeta>
          </>
        )}
        <Tarjeta>
          <Text style={estilos.etiqueta}>Pronto en la app</Text>
          <Text style={estilos.subtitulo}>Comunicados y tareas llegarán en las próximas versiones. Mientras tanto, te avisaremos aquí.</Text>
        </Tarjeta>
        <Tarjeta>
          <Text style={[estilos.etiqueta, enfocar !== 'inicio' && { color }]}>Notificaciones</Text>
          <Preferencias api={api} color={color} />
          <Boton titulo="Enviarme una notificación de prueba" borde color={color} cargando={envio.enviando}
            alPulsar={() => envio.ejecutar(async () => {
              const r = await api.pedir<{ enviados: number }>('/notificaciones/prueba', { metodo: 'POST' });
              setOk(r.enviados ? 'Enviada. Debería llegarte en unos segundos.' : 'No se envió: revisa que los comunicados estén activados y que diste permiso de notificaciones.');
            })} />
          {ok && <Text style={estilos.subtitulo}>{ok}</Text>}
          <MensajeError>{envio.error}</MensajeError>
        </Tarjeta>
        <Tarjeta>
          <Text style={estilos.etiqueta}>Seguridad</Text>
          {biometria.disponible ? (
            <View style={estilos.fila}>
              <Text style={{ fontSize: 16, color: colores.texto, flex: 1 }}>Entrar con huella o rostro</Text>
              <Switch accessibilityLabel="Entrar con huella o rostro" value={biometria.activa} trackColor={{ true: color }} onValueChange={alBiometria} />
            </View>
          ) : (
            <Text style={estilos.subtitulo}>Este teléfono no tiene huella ni reconocimiento facial configurado.</Text>
          )}
        </Tarjeta>
        {estado.colegios.length > 1 && <Boton titulo="Cambiar de colegio" borde color={color} alPulsar={alCambiarColegio} />}
        <Boton titulo="Cerrar sesión" borde color={colores.error} alPulsar={alSalir} />
      </ScrollView>
    </View>
  );
}
