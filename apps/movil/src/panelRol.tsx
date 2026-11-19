import { Text } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { BottomTabNavigationProp } from '@react-navigation/bottom-tabs';
import { AsistenciaApp, EstadoConexion, PuertaQrApp } from './academico';
import { AulaApp, EntrarMoodleApp } from './aula';
import { BibliotecaApp } from './biblioteca';
import { PaseComedorApp } from './comedor';
import { useApp } from './contexto';
import type { PestanasParams } from './navegacionLogica';
import { EnfermeriaApp, RecorridoApp } from './personal';
import { PorteriaApp } from './porteria';
import { useTema } from './tema';
import { MensajeError } from './ui';
import { Cabecera, Hoja, Pantalla, Vacio } from './vistas';

/**
 * Inicio de los grupos que se rehacen en S37–S39 (alumno, docente, operación y oficina): las funciones
 * de su rol, como hasta ahora, con la cabecera y el perfil nuevos.
 */
export function PanelRol() {
  const nav = useNavigation<BottomTabNavigationProp<PestanasParams>>();
  const { api, colegio, color, grupo, aviso, estado } = useApp();
  const { c, t } = useTema();
  const roles = colegio.roles;
  const tiene = (...r: string[]) => roles.some((x) => r.includes(x));
  return (
    <Pantalla cabecera={<Cabecera alPerfil={() => nav.navigate('perfil')} />}>
      {aviso && <Hoja><Text style={{ color: c.texto, fontSize: t(15) }}>{aviso}</Text></Hoja>}
      {colegio.estado === 'suspendido' && <MensajeError>El acceso del colegio está pausado. Comunícate con el colegio.</MensajeError>}
      <EstadoConexion color={color} />
      {grupo === 'docente' && <AsistenciaApp api={api} color={color} />}
      {grupo === 'docente' && colegio.modulos?.includes('MDL') && <EntrarMoodleApp api={api} color={color} />}
      {grupo === 'operacion' && tiene('docente', 'tutor', 'auxiliar', 'coordinador') && <AsistenciaApp api={api} color={color} />}
      {grupo === 'operacion' && tiene('auxiliar', 'porteria') && <PuertaQrApp api={api} color={color} />}
      {grupo === 'operacion' && tiene('porteria') && <PorteriaApp api={api} color={color} />}
      {grupo === 'operacion' && tiene('conductor', 'movilidad') && <RecorridoApp api={api} color={color} />}
      {grupo === 'operacion' && tiene('enfermeria') && <EnfermeriaApp api={api} color={color} />}
      {grupo === 'operacion' && tiene('comedor', 'nutricionista') && <PaseComedorApp api={api} color={color} />}
      {grupo === 'alumno' && <AulaApp api={api} color={color} />}
      {grupo === 'alumno' && colegio.modulos?.includes('MDL') && <EntrarMoodleApp api={api} color={color} />}
      {grupo === 'alumno' && colegio.modulos?.includes('BIB') && <BibliotecaApp api={api} color={color} />}
      {grupo === 'oficina' && (
        <Hoja>
          <Text style={{ color: c.texto, fontSize: t(17), fontWeight: '700' }}>Hola, {estado.usuario.nombres}</Text>
          <Vacio texto="Tu trabajo diario está en la web del colegio. Aquí recibes los avisos y puedes configurarlos en tu perfil." />
        </Hoja>
      )}
    </Pantalla>
  );
}
