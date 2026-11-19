import { useState, type ComponentProps, type ReactNode } from 'react';
import { Modal, Pressable, ScrollView, Text, View, type ViewStyle } from 'react-native';
import { Image } from 'expo-image';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useApp, useFamilia } from './contexto';
import { nombreCorto, type HijoResumen, type Tono } from './inicioFamiliaLogica';
import { iniciales } from './marca';
import { Escudo } from './pantallas';
import { TONOS, useTema } from './tema';

// S36 · Piezas visuales de las pantallas nuevas (según los diseños del kit): cabecera con el colegio,
// tarjetas, insignias, selector de hijo y accesos de servicios. Respetan el tamaño de letra y el contraste.

export type Icono = ComponentProps<typeof Ionicons>['name'];

export function Cabecera({ titulo, alAvisos, alPerfil, sinLeer = 0 }: { titulo?: string; alAvisos?: () => void; alPerfil?: () => void; sinLeer?: number }) {
  const { api, colegio, estado } = useApp();
  const { c, t } = useTema();
  const arriba = useSafeAreaInsets().top;
  return (
    <View style={{ backgroundColor: c.tarjeta, paddingTop: arriba + 10, paddingBottom: 12, paddingHorizontal: 16, flexDirection: 'row', alignItems: 'center', gap: 12, borderBottomWidth: 1, borderColor: c.borde }}>
      <Escudo colegio={colegio} api={api} tamano={40} />
      <View style={{ flex: 1 }}>
        <Text style={{ color: c.texto, fontSize: t(titulo ? 18 : 16), fontWeight: '700' }} numberOfLines={1}>{titulo ?? colegio.nombre}</Text>
        <Text style={{ color: c.suave, fontSize: t(13) }} numberOfLines={1}>{titulo ? colegio.nombre : `${estado.usuario.nombres} ${estado.usuario.apellidos}`}</Text>
      </View>
      {alAvisos && (
        <Pressable accessibilityRole="button" accessibilityLabel={sinLeer ? `Avisos, ${sinLeer} sin leer` : 'Avisos'} onPress={alAvisos} hitSlop={10}>
          <Ionicons name="notifications-outline" size={26} color={c.texto} />
          {sinLeer > 0 && <View style={{ position: 'absolute', top: 0, right: 0, width: 10, height: 10, borderRadius: 5, backgroundColor: '#16a34a', borderWidth: 2, borderColor: c.tarjeta }} />}
        </Pressable>
      )}
      {alPerfil && (
        <Pressable accessibilityRole="button" accessibilityLabel="Mi perfil" onPress={alPerfil} hitSlop={10}
          style={{ width: 36, height: 36, borderRadius: 18, backgroundColor: c.acento, alignItems: 'center', justifyContent: 'center' }}>
          <Ionicons name="person" size={18} color={c.sobreAcento} />
        </Pressable>
      )}
    </View>
  );
}

export function Hoja({ children, estilo }: { children: ReactNode; estilo?: ViewStyle }) {
  const { c } = useTema();
  return <View style={[{ backgroundColor: c.tarjeta, borderRadius: 18, padding: 16, borderWidth: 1, borderColor: c.borde, gap: 12 }, estilo]}>{children}</View>;
}

export function TituloHoja({ icono, titulo, derecha }: { icono?: Icono; titulo: string; derecha?: ReactNode }) {
  const { c, t } = useTema();
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
      {icono && (
        <View style={{ width: 36, height: 36, borderRadius: 10, backgroundColor: c.suaveAcento, alignItems: 'center', justifyContent: 'center' }}>
          <Ionicons name={icono} size={20} color={c.acento} />
        </View>
      )}
      <Text style={{ flex: 1, color: c.texto, fontSize: t(18), fontWeight: '700' }} accessibilityRole="header">{titulo}</Text>
      {derecha}
    </View>
  );
}

export function Insignia({ texto, tono }: { texto: string; tono: Tono }) {
  const { t } = useTema();
  const k = TONOS[tono];
  return (
    <View style={{ backgroundColor: k.fondo, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 }}>
      <Text style={{ color: k.texto, fontSize: t(13), fontWeight: '700' }}>{texto}</Text>
    </View>
  );
}

export function Enlace({ texto, alPulsar }: { texto: string; alPulsar: () => void }) {
  const { c, t } = useTema();
  return (
    <Pressable accessibilityRole="link" onPress={alPulsar} hitSlop={8} style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingTop: 4 }}>
      <Text style={{ color: c.acento, fontSize: t(15), fontWeight: '600' }}>{texto}</Text>
      <Ionicons name="arrow-forward" size={18} color={c.acento} />
    </Pressable>
  );
}

export function BotonGrande({ titulo, icono, alPulsar, fondo, cargando }: { titulo: string; icono?: Icono; alPulsar: () => void; fondo?: string; cargando?: boolean }) {
  const { c, t } = useTema();
  const color = fondo ?? c.acento;
  return (
    <Pressable accessibilityRole="button" disabled={cargando} onPress={alPulsar}
      style={({ pressed }) => ({ backgroundColor: color, borderRadius: 12, minHeight: 50, paddingHorizontal: 16, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10, opacity: pressed || cargando ? 0.7 : 1 })}>
      {icono && <Ionicons name={icono} size={20} color={fondo ? '#ffffff' : c.sobreAcento} />}
      <Text style={{ color: fondo ? '#ffffff' : c.sobreAcento, fontSize: t(16), fontWeight: '700' }}>{cargando ? 'Un momento…' : titulo}</Text>
    </Pressable>
  );
}

export function Pantalla({ children, cabecera, refresco }: { children: ReactNode; cabecera: ReactNode; refresco?: ReactNode }) {
  const { c } = useTema();
  return (
    <View style={{ flex: 1, backgroundColor: c.fondo }}>
      {cabecera}
      <ScrollView contentContainerStyle={{ padding: 16, gap: 14, paddingBottom: 32 }} refreshControl={refresco as never}>
        {children}
      </ScrollView>
    </View>
  );
}

export function FotoAlumno({ hijo, tamano = 56 }: { hijo: Pick<HijoResumen, 'id' | 'nombres' | 'apellidos' | 'tiene_foto'>; tamano?: number }) {
  const { api } = useApp();
  const { c } = useTema();
  const [falla, setFalla] = useState(false);
  if (hijo.tiene_foto && !falla) {
    return (
      <Image accessibilityLabel={`Foto de ${hijo.nombres}`} source={{ uri: `${api.base}/familia/alumnos/${hijo.id}/foto`, headers: api.cabeceras() }}
        cachePolicy="memory" style={{ width: tamano, height: tamano, borderRadius: tamano / 2, backgroundColor: c.suaveAcento }} contentFit="cover" onError={() => setFalla(true)} />
    );
  }
  return (
    <View style={{ width: tamano, height: tamano, borderRadius: tamano / 2, backgroundColor: c.suaveAcento, alignItems: 'center', justifyContent: 'center' }}>
      <Text style={{ color: c.acento, fontWeight: '700', fontSize: tamano / 2.6 }}>{iniciales(`${hijo.nombres} ${hijo.apellidos}`)}</Text>
    </View>
  );
}

/** Tarjeta del hijo elegido (foto, grado y matrícula) con el botón para cambiar de hijo. */
export function SelectorHijo({ compacto }: { compacto?: boolean }) {
  const { resumen, hijo, elegir } = useFamilia();
  const { c, t } = useTema();
  const [abierto, setAbierto] = useState(false);
  if (!hijo || !resumen) return null;
  const varios = resumen.hijos.length > 1;
  const matricula = hijo.matricula === 'matriculado' ? `Matriculado ${hijo.anio ?? ''}`.trim() : hijo.matricula === 'reservado' ? `Vacante reservada ${hijo.anio ?? ''}`.trim() : 'Sin matrícula vigente';
  return (
    <>
      <Hoja estilo={{ flexDirection: 'row', alignItems: 'center', gap: 14, padding: compacto ? 12 : 16 }}>
        <FotoAlumno hijo={hijo} tamano={compacto ? 44 : 60} />
        <View style={{ flex: 1, gap: 2 }}>
          <Text style={{ color: c.texto, fontSize: t(compacto ? 16 : 19), fontWeight: '700' }} numberOfLines={compacto ? 1 : 2}>{compacto ? nombreCorto(hijo) : `${hijo.nombres} ${hijo.apellidos}`}</Text>
          {hijo.seccion && <Text style={{ color: c.suave, fontSize: t(14) }}>{hijo.seccion}</Text>}
          {!compacto && (
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
              <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: hijo.matricula === 'matriculado' ? '#16a34a' : '#d97706' }} />
              <Text style={{ color: hijo.matricula === 'matriculado' ? '#15803d' : '#b45309', fontSize: t(13), fontWeight: '600' }}>{matricula}</Text>
            </View>
          )}
        </View>
        {varios && (
          <Pressable accessibilityRole="button" accessibilityLabel="Cambiar de hijo" onPress={() => setAbierto(true)} hitSlop={8}
            style={{ width: 44, height: 44, borderRadius: 12, backgroundColor: c.suaveAcento, alignItems: 'center', justifyContent: 'center' }}>
            <Ionicons name="swap-horizontal" size={22} color={c.acento} />
          </Pressable>
        )}
      </Hoja>
      <Modal visible={abierto} transparent animationType="fade" onRequestClose={() => setAbierto(false)}>
        <Pressable style={{ flex: 1, backgroundColor: 'rgba(0,0,0,0.4)', justifyContent: 'flex-end' }} onPress={() => setAbierto(false)}>
          <View style={{ backgroundColor: c.tarjeta, borderTopLeftRadius: 20, borderTopRightRadius: 20, padding: 20, gap: 8 }}>
            <Text style={{ color: c.texto, fontSize: t(18), fontWeight: '700', marginBottom: 6 }}>¿De quién quieres ver la información?</Text>
            {resumen.hijos.map((h) => (
              <Pressable key={h.id} accessibilityRole="button" accessibilityState={{ selected: h.id === hijo.id }} onPress={() => { elegir(h.id); setAbierto(false); }}
                style={{ flexDirection: 'row', alignItems: 'center', gap: 12, padding: 10, borderRadius: 12, backgroundColor: h.id === hijo.id ? c.suaveAcento : 'transparent' }}>
                <FotoAlumno hijo={h} tamano={44} />
                <View style={{ flex: 1 }}>
                  <Text style={{ color: c.texto, fontSize: t(16), fontWeight: '600' }}>{h.nombres} {h.apellidos}</Text>
                  {h.seccion && <Text style={{ color: c.suave, fontSize: t(14) }}>{h.seccion}</Text>}
                </View>
                {h.id === hijo.id && <Ionicons name="checkmark-circle" size={22} color={c.acento} />}
              </Pressable>
            ))}
          </View>
        </Pressable>
      </Modal>
    </>
  );
}

export function Acceso({ icono, titulo, alPulsar }: { icono: Icono; titulo: string; alPulsar: () => void }) {
  const { c, t } = useTema();
  return (
    <Pressable accessibilityRole="button" onPress={alPulsar}
      style={({ pressed }) => ({ width: '31.5%', backgroundColor: c.tarjeta, borderRadius: 16, borderWidth: 1, borderColor: c.borde, paddingVertical: 14, alignItems: 'center', gap: 8, opacity: pressed ? 0.7 : 1 })}>
      <View style={{ width: 44, height: 44, borderRadius: 12, backgroundColor: c.suaveAcento, alignItems: 'center', justifyContent: 'center' }}>
        <Ionicons name={icono} size={22} color={c.acento} />
      </View>
      <Text style={{ color: c.texto, fontSize: t(13), fontWeight: '600', textAlign: 'center' }} numberOfLines={2}>{titulo}</Text>
    </Pressable>
  );
}

export function Segmentos<T extends string>({ opciones, valor, alCambiar }: { opciones: { valor: T; texto: string }[]; valor: T; alCambiar: (v: T) => void }) {
  const { c, t } = useTema();
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
      {opciones.map((o) => {
        const activo = o.valor === valor;
        return (
          <Pressable key={o.valor} accessibilityRole="tab" accessibilityState={{ selected: activo }} onPress={() => alCambiar(o.valor)}
            style={{ paddingHorizontal: 16, paddingVertical: 9, borderRadius: 999, backgroundColor: activo ? c.acento : c.tarjeta, borderWidth: 1, borderColor: activo ? c.acento : c.borde }}>
            <Text style={{ color: activo ? c.sobreAcento : c.texto, fontSize: t(14), fontWeight: '600' }}>{o.texto}</Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

export function Vacio({ texto }: { texto: string }) {
  const { c, t } = useTema();
  return <Text style={{ color: c.suave, fontSize: t(15), lineHeight: t(21) }}>{texto}</Text>;
}
