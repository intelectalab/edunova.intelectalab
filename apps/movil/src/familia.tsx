import { useCallback, useEffect, useState } from 'react';
import { Linking, Switch, Text, View } from 'react-native';
import type { Cliente } from './api';
import { Boton, colores, estilos, MensajeError } from './ui';

type Cargo = { id: number; descripcion: string; vencimiento: string; emision: string; saldo: number; pagado: number; estado: string; vencido: boolean };
type Hijo = { alumno_id: number; alumno: string; deuda_vencida: number; por_vencer: number; proximo: { descripcion: string; vencimiento: string; saldo: number } | null; cargos: Cargo[] };
type Comprobante = { id: number; nombre_tipo: string; serie: string; numero: number; fecha_emision: string; total: number; pdf_archivo_id: number | null };
type EstadoCuenta = { hijos: Hijo[]; comprobantes: Comprobante[] };
type Ratificable = { alumno_id: number; alumno: string; anio: number; seccion: { nombre: string } | null; condiciones: { version: number; contenido: string } | null; matricula: { estado: string } | null };

const soles = (n: number) => `S/ ${n.toFixed(2)}`;
const hoy = () => new Date().toISOString().slice(0, 10);

function useAccion() {
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const ejecutar = async (fn: () => Promise<void>) => {
    setEnviando(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo completar la acción.');
    } finally {
      setEnviando(false);
    }
  };
  return { enviando, error, ejecutar };
}

/**
 * EDUN-82 · Estado de cuenta en la app: cargos por hijo, próximo vencimiento y comprobantes
 * descargables. EDUN-73 · Desde aquí se pagan en línea los cargos ya emitidos.
 */
export function EstadoCuentaApp({ api, color }: { api: Cliente; color: string }) {
  const [datos, setDatos] = useState<EstadoCuenta | null>(null);
  const [elegidos, setElegidos] = useState<number[]>([]);
  const accion = useAccion();
  const cargar = useCallback(() => api.pedir<EstadoCuenta>('/familia/estado-cuenta').then(setDatos).catch(() => setDatos({ hijos: [], comprobantes: [] })), [api]);
  useEffect(() => {
    cargar();
  }, [cargar]);
  if (!datos) return <Text style={estilos.subtitulo}>Cargando…</Text>;
  if (!datos.hijos.length) return <Text style={estilos.subtitulo}>No hay cargos registrados.</Text>;
  const total = datos.hijos.flatMap((h) => h.cargos).filter((c) => elegidos.includes(c.id)).reduce((s, c) => s + c.saldo, 0);
  const pagable = (c: Cargo) => ['pendiente', 'parcial'].includes(c.estado) && c.emision <= hoy();

  return (
    <>
      {datos.hijos.map((h) => (
        <View key={h.alumno_id} style={{ gap: 6, marginBottom: 8 }}>
          <Text style={{ fontSize: 17, fontWeight: '700', color: colores.texto }}>{h.alumno}</Text>
          <Text style={{ color: h.deuda_vencida > 0 ? colores.error : colores.suave }}>
            {h.deuda_vencida > 0 ? `Vencido: ${soles(h.deuda_vencida)} · ` : ''}Por vencer: {soles(h.por_vencer)}
          </Text>
          {h.proximo && <Text style={estilos.subtitulo}>Próximo: {h.proximo.descripcion}, vence {h.proximo.vencimiento} ({soles(h.proximo.saldo)})</Text>}
          {h.cargos.filter((c) => pagable(c)).map((c) => (
            <View key={c.id} style={estilos.fila}>
              <Text style={{ flex: 1, color: c.vencido ? colores.error : colores.texto }}>{c.descripcion} · {soles(c.saldo)}</Text>
              <Switch accessibilityLabel={`Pagar ${c.descripcion}`} value={elegidos.includes(c.id)} trackColor={{ true: color }}
                onValueChange={(v) => setElegidos(v ? [...elegidos, c.id] : elegidos.filter((x) => x !== c.id))} />
            </View>
          ))}
          {h.cargos.filter((c) => c.estado === 'pagado').slice(-3).map((c) => (
            <Text key={c.id} style={{ color: colores.suave }}>✓ {c.descripcion} pagado</Text>
          ))}
        </View>
      ))}
      {elegidos.length > 0 && (
        <Boton titulo={`Pagar ${soles(total)} en línea`} color={color} cargando={accion.enviando}
          alPulsar={() => accion.ejecutar(async () => {
            const r = await api.pedir<{ url: string }>('/familia/pagos/ordenes', { metodo: 'POST', cuerpo: { cargos: elegidos } });
            await Linking.openURL(r.url);
          })} />
      )}
      {datos.comprobantes.length > 0 && <Text style={[estilos.etiqueta, { marginTop: 8 }]}>Comprobantes</Text>}
      {datos.comprobantes.slice(0, 6).map((c) => (
        <View key={c.id} style={estilos.fila}>
          <Text style={{ flex: 1, color: colores.texto }}>{c.serie}-{c.numero} · {soles(c.total)}</Text>
          {c.pdf_archivo_id && (
            <Boton titulo="PDF" borde color={color} alPulsar={() => accion.ejecutar(async () => {
              await Linking.openURL((await api.pedir<{ url: string }>(`/familia/comprobantes/${c.id}`)).url);
            })} />
          )}
        </View>
      ))}
      <MensajeError>{accion.error}</MensajeError>
    </>
  );
}

/** EDUN-55 · Ratificar la matrícula del próximo año desde la app. */
export function RatificacionApp({ api, color, alRatificar }: { api: Cliente; color: string; alRatificar: () => void }) {
  const [hijos, setHijos] = useState<Ratificable[] | null>(null);
  const [acepta, setAcepta] = useState<Record<number, boolean>>({});
  const [ok, setOk] = useState<string | null>(null);
  const accion = useAccion();
  const cargar = useCallback(() => api.pedir<{ hijos: Ratificable[] }>('/familia/ratificacion').then((r) => setHijos(r.hijos)).catch(() => setHijos([])), [api]);
  useEffect(() => {
    cargar();
  }, [cargar]);
  const pendientes = (hijos ?? []).filter((h) => !h.matricula && h.condiciones && h.seccion);
  if (!pendientes.length && !ok) return null;
  return (
    <View style={estilos.tarjeta}>
      <Text style={estilos.etiqueta}>Ratificar matrícula</Text>
      {ok && <Text style={{ color: colores.texto }}>{ok}</Text>}
      {pendientes.map((h) => (
        <View key={h.alumno_id} style={{ gap: 8 }}>
          <Text style={{ color: colores.texto }}>{h.alumno}: vacante en {h.seccion!.nombre} para {h.anio}.</Text>
          <Text style={estilos.subtitulo} numberOfLines={6}>{h.condiciones!.contenido}</Text>
          <View style={estilos.fila}>
            <Text style={{ flex: 1, color: colores.texto }}>Acepto las condiciones económicas {h.anio}</Text>
            <Switch accessibilityLabel="Acepto las condiciones económicas" value={!!acepta[h.alumno_id]} trackColor={{ true: color }} onValueChange={(v) => setAcepta({ ...acepta, [h.alumno_id]: v })} />
          </View>
          <Boton titulo="Ratificar" color={color} deshabilitado={!acepta[h.alumno_id]} cargando={accion.enviando}
            alPulsar={() => accion.ejecutar(async () => {
              const r = await api.pedir<{ estado: string }>('/familia/ratificacion', { metodo: 'POST', cuerpo: { alumno_id: h.alumno_id, acepta_condiciones: true } });
              setOk(r.estado === 'matriculado' ? `Matrícula ${h.anio} de ${h.alumno} confirmada.` : `Vacante reservada. Paga la matrícula de ${h.alumno} en «Mis pagos» para confirmarla.`);
              await cargar();
              alRatificar();
            })} />
        </View>
      ))}
      <MensajeError>{accion.error}</MensajeError>
    </View>
  );
}
