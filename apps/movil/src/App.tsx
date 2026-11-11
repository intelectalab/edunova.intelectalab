import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import * as Notifications from 'expo-notifications';
import { crearCliente, ErrorApi, siguientePaso, type EstadoSesion } from './api';
import { destinoDeAviso, type Destino } from './destinos';
import * as dispositivo from './dispositivo';
import { colorDeMarca } from './marca';
import { Aviso, DosPasos, ElegirColegio, Ingreso, Inicio } from './pantallas';
import { estilos } from './ui';

const API_URL = process.env.EXPO_PUBLIC_API_URL ?? 'http://localhost:8100/api/v1';

type Fase = 'cargando' | 'ingreso' | 'dos_pasos' | 'configurar_dos_pasos' | 'sin_colegio' | 'elegir_colegio' | 'inicio';

/**
 * EDUN-195 · Base de la app: una sola app para todos los colegios (EDUN-200), con ingreso por
 * DNI o correo, segundo factor cuando el rol lo exige, elección de colegio y biometría opcional
 * para volver a entrar. El token vive solo en el almacén seguro del teléfono.
 */
export default function App() {
  const sesion = useRef<{ token: string | null; colegio: number | null }>({ token: null, colegio: null });
  const api = useMemo(() => crearCliente(API_URL, () => sesion.current), []);
  const [fase, setFase] = useState<Fase>('cargando');
  const [estado, setEstado] = useState<EstadoSesion | null>(null);
  const [biometria, setBiometria] = useState({ disponible: false, activa: false });
  const [destino, setDestino] = useState<Destino>({ pantalla: 'inicio' });

  const aplicar = useCallback(async (e: EstadoSesion) => {
    setEstado(e);
    sesion.current.colegio = e.colegio_activo?.id ?? null;
    if (sesion.current.token) await dispositivo.guardarSesion(sesion.current.token, sesion.current.colegio);
    const paso = siguientePaso(e);
    setFase(paso);
    if (paso === 'inicio') dispositivo.registrarPush(api).catch(() => undefined);
  }, [api]);

  const salir = useCallback(async () => {
    await dispositivo.quitarPush(api);
    await api.pedir('/auth/salir', { metodo: 'POST' }).catch(() => undefined);
    await dispositivo.borrarSesion();
    sesion.current = { token: null, colegio: null };
    setEstado(null);
    setBiometria((b) => ({ ...b, activa: false }));
    setFase('ingreso');
  }, [api]);

  // Al abrir: si hay sesión guardada, pedir biometría (si la activó) y retomar.
  useEffect(() => {
    (async () => {
      const guardada = await dispositivo.leerSesion();
      const disponible = await dispositivo.biometriaDisponible().catch(() => false);
      setBiometria({ disponible, activa: guardada.biometria && disponible });
      if (!guardada.token) return setFase('ingreso');
      if (guardada.biometria && disponible && !(await dispositivo.confirmarConBiometria())) return setFase('ingreso');
      sesion.current = { token: guardada.token, colegio: guardada.colegio };
      try {
        await aplicar(await api.pedir<EstadoSesion>('/auth/yo'));
      } catch (e) {
        if (e instanceof ErrorApi && e.estado === 401) await dispositivo.borrarSesion();
        setFase('ingreso');
      }
    })();
  }, [api, aplicar]);

  // Tocar un aviso abre la pantalla relacionada (también si la app estaba cerrada).
  useEffect(() => {
    const abrir = (r: Notifications.NotificationResponse | null) => {
      if (r) setDestino(destinoDeAviso(r.notification.request.content.data as Record<string, unknown>, r.notification.request.content.title));
    };
    Notifications.getLastNotificationResponseAsync().then(abrir).catch(() => undefined);
    const sub = Notifications.addNotificationResponseReceivedListener(abrir);
    return () => sub.remove();
  }, []);

  const color = colorDeMarca(estado?.colegio_activo?.marca?.color_primario);

  return (
    <View style={estilos.pantalla}>
      <StatusBar style={fase === 'inicio' ? 'light' : 'dark'} />
      {fase === 'cargando' && <ActivityIndicator style={{ marginTop: 120 }} color={color} size="large" />}
      {fase === 'ingreso' && (
        <Ingreso
          alIngresar={async (usuario, password) => {
            const r = await api.pedir<EstadoSesion & { token: string }>('/auth/ingresar', { metodo: 'POST', cuerpo: { usuario, password, dispositivo: 'app' } });
            sesion.current = { token: r.token, colegio: null };
            await aplicar(r);
          }}
        />
      )}
      {fase === 'dos_pasos' && (
        <DosPasos
          alVerificar={async (codigo) => {
            await api.pedir('/auth/dos-pasos/verificar', { metodo: 'POST', cuerpo: { codigo } });
            await aplicar(await api.pedir<EstadoSesion>('/auth/yo'));
          }}
          alSalir={salir}
        />
      )}
      {fase === 'configurar_dos_pasos' && (
        <Aviso titulo="Activa la verificación en dos pasos" texto="Tu rol la exige. Actívala primero en la web del colegio (Mi perfil y seguridad) y luego vuelve a ingresar aquí." alSalir={salir} />
      )}
      {fase === 'sin_colegio' && <Aviso titulo="Sin colegio asignado" texto="Tu cuenta aún no está vinculada a un colegio. Comunícate con el colegio." alSalir={salir} />}
      {fase === 'elegir_colegio' && estado && (
        <ElegirColegio estado={estado} alElegir={async (id) => aplicar(await api.pedir<EstadoSesion>('/auth/colegio', { metodo: 'POST', cuerpo: { institucion_id: id } }))} alSalir={salir} />
      )}
      {fase === 'inicio' && estado && (
        <Inicio
          estado={estado}
          api={api}
          aviso={destino.aviso ?? null}
          enfocar={destino.pantalla}
          biometria={biometria}
          alBiometria={async (activa) => {
            if (activa && !(await dispositivo.confirmarConBiometria())) return;
            await dispositivo.activarBiometria(activa);
            setBiometria((b) => ({ ...b, activa }));
          }}
          alCambiarColegio={() => setFase('elegir_colegio')}
          alSalir={salir}
        />
      )}
    </View>
  );
}
