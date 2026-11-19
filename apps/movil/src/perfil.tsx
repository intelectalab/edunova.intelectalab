import { useState, type ReactNode } from 'react';
import { Pressable, Switch, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useApp } from './contexto';
import { NOMBRE_GRUPO } from './navegacionLogica';
import { Preferencias, useEnvio } from './pantallas';
import { useTema } from './tema';
import { MensajeError } from './ui';
import { BotonGrande, Cabecera, Hoja, Pantalla, Segmentos, TituloHoja } from './vistas';

/**
 * S36 · Perfil (todas las personas): modo de la app si tiene varios roles, avisos por tipo, entrar con
 * huella o rostro, tamaño de letra y alto contraste, cambiar de colegio y cerrar sesión.
 */
export function PerfilPantalla() {
  const { api, estado, colegio, grupo, grupos, cambiarGrupo, biometria, alBiometria, alCambiarColegio, alSalir } = useApp();
  const { acc, cambiar, c, t } = useTema();
  const envio = useEnvio();
  const [ok, setOk] = useState<string | null>(null);
  const u = estado.usuario;

  const fila = (texto: string, control: ReactNode) => (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 6 }}>
      <Text style={{ flex: 1, color: c.texto, fontSize: t(16) }}>{texto}</Text>
      {control}
    </View>
  );

  return (
    <Pantalla cabecera={<Cabecera titulo="Mi perfil" />}>
      <Hoja estilo={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}>
        <View style={{ width: 56, height: 56, borderRadius: 28, backgroundColor: c.acento, alignItems: 'center', justifyContent: 'center' }}>
          <Ionicons name="person" size={28} color={c.sobreAcento} />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={{ color: c.texto, fontSize: t(18), fontWeight: '700' }}>{u.nombres} {u.apellidos}</Text>
          {u.email && <Text style={{ color: c.suave, fontSize: t(14) }}>{u.email}</Text>}
          <Text style={{ color: c.suave, fontSize: t(14) }}>{colegio.nombre}</Text>
        </View>
      </Hoja>

      {grupos.length > 1 && (
        <Hoja>
          <TituloHoja icono="swap-horizontal" titulo="Usar la app como" />
          <Segmentos opciones={grupos.map((g) => ({ valor: g, texto: NOMBRE_GRUPO[g] }))} valor={grupo} alCambiar={cambiarGrupo} />
        </Hoja>
      )}

      <Hoja>
        <TituloHoja icono="text" titulo="Lectura" />
        <Text style={{ color: c.suave, fontSize: t(14) }}>Tamaño de letra</Text>
        <Segmentos opciones={[{ valor: '1', texto: 'Normal' }, { valor: '1.25', texto: 'Grande' }, { valor: '1.5', texto: 'Muy grande' }]}
          valor={String(acc.escala)} alCambiar={(v) => cambiar({ ...acc, escala: Number(v) as 1 | 1.25 | 1.5 })} />
        {fila('Alto contraste', <Switch accessibilityLabel="Alto contraste" value={acc.contraste} trackColor={{ true: c.acento }} onValueChange={(v) => cambiar({ ...acc, contraste: v })} />)}
      </Hoja>

      <Hoja>
        <TituloHoja icono="notifications-outline" titulo="Notificaciones" />
        <Preferencias api={api} color={c.acento} />
        <BotonGrande titulo="Enviarme una notificación de prueba" icono="paper-plane-outline" cargando={envio.enviando}
          alPulsar={() => envio.ejecutar(async () => {
            const r = await api.pedir<{ enviados: number }>('/notificaciones/prueba', { metodo: 'POST' });
            setOk(r.enviados ? 'Enviada. Debería llegarte en unos segundos.' : 'No se envió: revisa que los comunicados estén activados y que diste permiso de notificaciones.');
          })} />
        {ok && <Text style={{ color: c.suave, fontSize: t(14) }}>{ok}</Text>}
        <MensajeError>{envio.error}</MensajeError>
      </Hoja>

      <Hoja>
        <TituloHoja icono="lock-closed-outline" titulo="Seguridad" />
        {biometria.disponible
          ? fila('Entrar con huella o rostro', <Switch accessibilityLabel="Entrar con huella o rostro" value={biometria.activa} trackColor={{ true: c.acento }} onValueChange={alBiometria} />)
          : <Text style={{ color: c.suave, fontSize: t(14) }}>Este teléfono no tiene huella ni reconocimiento facial configurado.</Text>}
      </Hoja>

      {estado.colegios.length > 1 && (
        <Pressable accessibilityRole="button" onPress={alCambiarColegio}>
          <Hoja estilo={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
            <Ionicons name="school-outline" size={22} color={c.acento} />
            <Text style={{ flex: 1, color: c.texto, fontSize: t(16), fontWeight: '600' }}>Cambiar de colegio</Text>
            <Ionicons name="chevron-forward" size={20} color={c.suave} />
          </Hoja>
        </Pressable>
      )}
      <BotonGrande titulo="Cerrar sesión" icono="log-out-outline" fondo="#b91c1c" alPulsar={alSalir} />
    </Pantalla>
  );
}
