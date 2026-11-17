import { useState } from 'react';
import { Text, View } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import type { Cliente } from './api';
import { Boton, Campo, colores, estilos } from './ui';

type Resultado = { ok: boolean; alumno: string | null; alerta: string | null; dieta_especial: boolean };

/**
 * EDUN-170 / EDUN-171 · Pase de comedor desde el celular o la tablet: escanea el QR del carné (o
 * escribe el DNI) y avisa si no está inscrito, si ya comió o si tiene dieta especial (sin detalle).
 */
export function PaseComedorApp({ api, color }: { api: Cliente; color: string }) {
  const [permiso, pedirPermiso] = useCameraPermissions();
  const [activo, setActivo] = useState(false);
  const [ultimo, setUltimo] = useState<string | null>(null);
  const [resultado, setResultado] = useState<Resultado | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [dni, setDni] = useState('');
  const leer = async (codigo: string) => {
    if (codigo === ultimo) return;
    setUltimo(codigo);
    setError(null);
    try {
      setResultado(await api.pedir<Resultado>('/comedor/servidos', { metodo: 'POST', cuerpo: { codigo, origen: 'qr' } }));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo registrar. Revisa la conexión.');
    }
    setTimeout(() => setUltimo(null), 2500);
  };
  const colorResultado = !resultado ? colores.texto : !resultado.ok ? colores.error : resultado.dieta_especial ? '#b45309' : '#15803d';
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Pase de comedor</Text>
      {!activo ? (
        <Boton titulo="Abrir escáner" color={color} alPulsar={async () => { if (!permiso?.granted) await pedirPermiso(); setActivo(true); }} />
      ) : permiso?.granted ? (
        <>
          <CameraView style={{ height: 260, borderRadius: 12 }} barcodeScannerSettings={{ barcodeTypes: ['qr'] }} onBarcodeScanned={({ data }) => leer(data)} />
          <Boton titulo="Cerrar escáner" borde color={color} alPulsar={() => setActivo(false)} />
        </>
      ) : <Text style={estilos.subtitulo}>Da permiso de cámara para escanear los carnés.</Text>}
      <Campo etiqueta="Sin carné: DNI del alumno" value={dni} onChangeText={(t) => setDni(t.replace(/\s/g, '').toUpperCase())} autoCapitalize="characters" maxLength={12} />
      <Boton titulo="Registrar con DNI" borde color={color} deshabilitado={!/^(\d{8}|[A-Z0-9]{9,12})$/.test(dni)} alPulsar={async () => { await leer(dni); setDni(''); }} />
      {resultado && <Text style={{ fontSize: 17, fontWeight: '700', color: colorResultado }}>{resultado.alumno ?? ''}{resultado.ok ? ' · servido' : ''}{resultado.alerta ? `\n${resultado.alerta}` : ''}</Text>}
      {error && <Text style={estilos.error}>{error}</Text>}
    </View>
  );
}
