import { useEffect, useMemo, useState, type ComponentType } from 'react';
import { ActivityIndicator } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { Ionicons } from '@expo/vector-icons';
import { NavigationContainer, useNavigationContainerRef } from '@react-navigation/native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import type { Cliente, EstadoSesion } from './api';
import { useSincronizacion } from './colaDispositivo';
import { AppProvider, FamiliaProvider, useApp, useFamilia, type ContextoApp } from './contexto';
import type { Destino } from './destinos';
import { AcademicoFamilia, AvisosFamilia, InicioFamilia, PagosFamilia, ServicioFamilia } from './familiaApp';
import { colorDeMarca, textoSobre } from './marca';
import { grupoInicial, gruposDe, pestanaDeAviso, pestanasDe, type Grupo, type Pestana, type PestanasParams, type RaizParams } from './navegacionLogica';
import { PanelRol } from './panelRol';
import { PerfilPantalla } from './perfil';
import { TemaProvider, useTema } from './tema';
import type { Icono } from './vistas';

const CLAVE_GRUPO = 'edunova.app.grupo';
const Raiz = createNativeStackNavigator<RaizParams>();
const Tabs = createBottomTabNavigator<PestanasParams>();

const TITULO: Record<Pestana, string> = { inicio: 'Inicio', pagos: 'Pagos', academico: 'Académico', avisos: 'Avisos', perfil: 'Perfil' };
const ICONO: Record<Pestana, [Icono, Icono]> = {
  inicio: ['grid', 'grid-outline'],
  pagos: ['wallet', 'wallet-outline'],
  academico: ['book', 'book-outline'],
  avisos: ['megaphone', 'megaphone-outline'],
  perfil: ['person-circle', 'person-circle-outline'],
};

type Props = {
  estado: EstadoSesion;
  api: Cliente;
  destino: Destino;
  biometria: { disponible: boolean; activa: boolean };
  alBiometria: (v: boolean) => void;
  alCambiarColegio: () => void;
  alSalir: () => void;
};

/**
 * S36 · Navegación según el rol (MOB-H06): pestañas inferiores por grupo, la pantalla de un servicio
 * encima, y los avisos push que abren su pestaña (MOB-H02). Quien tiene varios roles elige el modo en Perfil.
 */
export function Navegacion({ estado, api, destino, biometria, alBiometria, alCambiarColegio, alSalir }: Props) {
  const colegio = estado.colegio_activo!;
  const color = colorDeMarca(colegio.marca?.color_primario);
  const grupos = useMemo(() => gruposDe(colegio.roles), [colegio.roles]);
  const [grupo, setGrupo] = useState<Grupo | null>(null);
  useSincronizacion(api);

  useEffect(() => {
    AsyncStorage.getItem(CLAVE_GRUPO).then((g) => setGrupo(grupoInicial(colegio.roles, g))).catch(() => setGrupo(grupoInicial(colegio.roles, null)));
  }, [colegio.roles]);

  if (!grupo) return <ActivityIndicator style={{ marginTop: 120 }} color={color} size="large" />;

  const valor: ContextoApp = {
    api, estado, colegio, color, grupo, grupos, aviso: destino.aviso ?? null, biometria, alBiometria, alCambiarColegio, alSalir,
    cambiarGrupo: (g) => {
      setGrupo(g);
      void AsyncStorage.setItem(CLAVE_GRUPO, g).catch(() => undefined);
    },
  };
  const contenido = <Rutas key={grupo} grupo={grupo} destino={destino} />;

  return (
    <SafeAreaProvider>
      <TemaProvider color={color} sobre={textoSobre(color)}>
        <AppProvider valor={valor}>
          {grupo === 'familia' ? <FamiliaProvider api={api}>{contenido}</FamiliaProvider> : contenido}
        </AppProvider>
      </TemaProvider>
    </SafeAreaProvider>
  );
}

function Rutas({ grupo, destino }: { grupo: Grupo; destino: Destino }) {
  const ref = useNavigationContainerRef<RaizParams>();
  const [lista, setLista] = useState(false);
  const { c, acc } = useTema();
  const { colegio } = useApp();
  const pestanas = pestanasDe(grupo, colegio.modulos);

  // Tocar un aviso abre su pestaña (también si la app estaba cerrada: espera a que la navegación esté lista).
  useEffect(() => {
    if (!lista || !destino.origen) return;
    ref.navigate('pestanas', { screen: pestanaDeAviso(destino.origen, pestanas) });
  }, [lista, destino, ref, pestanas]);

  return (
    <>
    <StatusBar style={acc.contraste ? 'light' : 'dark'} />
    <NavigationContainer ref={ref} onReady={() => setLista(true)}
      theme={{ dark: false, colors: { primary: c.acento, background: c.fondo, card: c.tarjeta, text: c.texto, border: c.borde, notification: '#16a34a' }, fonts: TEMA_FUENTES }}>
      <Raiz.Navigator screenOptions={{ headerShown: false }}>
        <Raiz.Screen name="pestanas">{() => <Pestanas grupo={grupo} pestanas={pestanas} />}</Raiz.Screen>
        <Raiz.Screen name="servicio" component={ServicioFamilia} />
      </Raiz.Navigator>
    </NavigationContainer>
    </>
  );
}

// Fuentes del sistema (el tema de React Navigation las pide explícitas).
const TEMA_FUENTES = {
  regular: { fontFamily: 'System', fontWeight: '400' as const },
  medium: { fontFamily: 'System', fontWeight: '500' as const },
  bold: { fontFamily: 'System', fontWeight: '700' as const },
  heavy: { fontFamily: 'System', fontWeight: '800' as const },
};

const PANTALLAS_FAMILIA: Record<Pestana, ComponentType> = {
  inicio: InicioFamilia,
  pagos: PagosFamilia,
  academico: AcademicoFamilia,
  avisos: AvisosFamilia,
  perfil: PerfilPantalla,
};

function Pestanas({ grupo, pestanas }: { grupo: Grupo; pestanas: Pestana[] }) {
  const { c } = useTema();
  const { resumen } = useFamilia();
  const sinLeer = grupo === 'familia' ? resumen?.comunicados_sin_leer ?? 0 : 0;
  return (
    <Tabs.Navigator screenOptions={({ route }) => ({
      headerShown: false,
      tabBarActiveTintColor: c.acento,
      tabBarInactiveTintColor: c.suave,
      tabBarStyle: { backgroundColor: c.tarjeta, borderTopColor: c.borde },
      // Las etiquetas de las pestañas no crecen con la letra grande: no caben y se cortan.
      tabBarLabelStyle: { fontSize: 12, fontWeight: '600' },
      tabBarIcon: ({ focused, color, size }) => <Ionicons name={ICONO[route.name][focused ? 0 : 1]} size={size} color={color} />,
    })}>
      {pestanas.map((p) => (
        <Tabs.Screen key={p} name={p} options={{ title: TITULO[p], tabBarBadge: p === 'avisos' && sinLeer ? sinLeer : undefined }}
          component={grupo === 'familia' ? PANTALLAS_FAMILIA[p] : p === 'perfil' ? PerfilPantalla : PanelRol} />
      ))}
    </Tabs.Navigator>
  );
}
