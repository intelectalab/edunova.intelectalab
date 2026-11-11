import type { ReactNode } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View, type TextInputProps } from 'react-native';
import { VERDE_EDUNOVA } from './marca';

export const colores = { fondo: '#f5f7f6', texto: '#111827', suave: '#6b7280', borde: '#d1d5db', error: '#b91c1c', blanco: '#ffffff' };

export function Boton({ titulo, alPulsar, color = VERDE_EDUNOVA, borde, cargando, deshabilitado }: {
  titulo: string; alPulsar: () => void; color?: string; borde?: boolean; cargando?: boolean; deshabilitado?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: !!(deshabilitado || cargando) }}
      disabled={deshabilitado || cargando}
      onPress={alPulsar}
      style={({ pressed }) => [estilos.boton, borde ? { borderColor: color, borderWidth: 1.5 } : { backgroundColor: color }, (pressed || deshabilitado) && { opacity: 0.6 }]}
    >
      {cargando ? <ActivityIndicator color={borde ? color : colores.blanco} /> : <Text style={[estilos.botonTexto, { color: borde ? color : colores.blanco }]}>{titulo}</Text>}
    </Pressable>
  );
}

export function Campo({ etiqueta, ...props }: TextInputProps & { etiqueta: string }) {
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={estilos.etiqueta}>{etiqueta}</Text>
      <TextInput accessibilityLabel={etiqueta} placeholderTextColor={colores.suave} style={estilos.campo} {...props} />
    </View>
  );
}

export function MensajeError({ children }: { children: ReactNode }) {
  return children ? <Text accessibilityRole="alert" style={estilos.error}>{children}</Text> : null;
}

export function Tarjeta({ children }: { children: ReactNode }) {
  return <View style={estilos.tarjeta}>{children}</View>;
}

export const estilos = StyleSheet.create({
  pantalla: { flex: 1, backgroundColor: colores.fondo },
  contenido: { padding: 20, gap: 14 },
  titulo: { fontSize: 24, fontWeight: '700', color: colores.texto },
  subtitulo: { fontSize: 15, color: colores.suave, lineHeight: 21 },
  etiqueta: { fontSize: 14, fontWeight: '600', color: colores.texto, marginBottom: 6 },
  campo: { borderWidth: 1, borderColor: colores.borde, borderRadius: 10, paddingHorizontal: 14, paddingVertical: 12, fontSize: 16, backgroundColor: colores.blanco, color: colores.texto },
  boton: { borderRadius: 10, paddingVertical: 14, alignItems: 'center', minHeight: 50, justifyContent: 'center' },
  botonTexto: { fontSize: 16, fontWeight: '700' },
  error: { color: colores.error, fontSize: 14, marginVertical: 6 },
  tarjeta: { backgroundColor: colores.blanco, borderRadius: 14, padding: 16, borderWidth: 1, borderColor: '#e5e7eb', gap: 10 },
  fila: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 10, gap: 12 },
});
