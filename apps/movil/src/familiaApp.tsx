import { useCallback, useEffect, useState, type ReactElement } from 'react';
import { Linking, Pressable, RefreshControl, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useNavigation, useRoute, type CompositeNavigationProp, type RouteProp } from '@react-navigation/native';
import type { BottomTabNavigationProp } from '@react-navigation/bottom-tabs';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { NotasApp } from './academico';
import { TareasHijosApp } from './aula';
import { BibliotecaApp } from './biblioteca';
import { AgendaApp, ComunicadosApp } from './comunicacion';
import { useApp, useFamilia } from './contexto';
import { EstadoCuentaApp, RatificacionApp } from './familia';
import { AutorizacionesApp, RecojoApp } from './familiaColegio';
import { calendarioMes, clasesDelDia, diaInicialHorario, estadoAsistencia, estadoPension, fechaLarga, hoyIso, moverMes, nombreMes, soles, tonoNota, type Horario, type Tono } from './inicioFamiliaLogica';
import { JustificarApp } from './justificaciones';
import { serviciosFamilia, tituloServicio, type ClaveServicio, type PestanasParams, type RaizParams, type SeccionAcademico } from './navegacionLogica';
import { ConveniosApp } from './personal';
import { FichaSaludApp, MenuComedorApp, MovilidadFamiliaApp } from './servicios';
import { TONOS, useTema } from './tema';
import { TiendaApp } from './tienda';
import { TramitesApp } from './tramites';
import { MensajeError } from './ui';
import { Acceso, BotonGrande, Cabecera, Enlace, Hoja, Insignia, Pantalla, Segmentos, SelectorHijo, TituloHoja, Vacio, type Icono } from './vistas';

type Nav = CompositeNavigationProp<BottomTabNavigationProp<PestanasParams>, NativeStackNavigationProp<RaizParams>>;

function CabeceraFamilia({ titulo }: { titulo?: string }) {
  const nav = useNavigation<Nav>();
  const { resumen } = useFamilia();
  return <Cabecera titulo={titulo} sinLeer={resumen?.comunicados_sin_leer ?? 0} alAvisos={() => nav.navigate('avisos')} alPerfil={() => nav.navigate('perfil')} />;
}

function useRefresco() {
  const { cargando, recargar } = useFamilia();
  const { c } = useTema();
  return <RefreshControl refreshing={cargando} onRefresh={() => void recargar()} tintColor={c.acento} colors={[c.acento]} />;
}

/** Abre la libreta publicada (enlace temporal). */
async function abrirLibreta(api: ReturnType<typeof useApp>['api'], id: number) {
  await Linking.openURL((await api.pedir<{ url: string }>(`/familia/libretas/${id}`)).url);
}

// ── Inicio (MOB-01): hijo, pensión, asistencia de hoy, notas recientes, comunicado y servicios.

export function InicioFamilia() {
  const nav = useNavigation<Nav>();
  const { api, colegio, color, aviso } = useApp();
  const { resumen, hijo, error, recargar } = useFamilia();
  const { c, t } = useTema();
  const refresco = useRefresco();
  const [errorAccion, setErrorAccion] = useState<string | null>(null);
  const [confirmando, setConfirmando] = useState(false);

  const comunicado = hijo?.comunicado ?? null;
  const asistencia = hijo?.asistencia ? estadoAsistencia(hijo.asistencia.hoy) : null;
  const servicios = serviciosFamilia(colegio.modulos);

  return (
    <Pantalla cabecera={<CabeceraFamilia />} refresco={refresco}>
      {aviso && <Hoja><Text style={{ color: c.texto, fontSize: t(15) }}>{aviso}</Text></Hoja>}
      {colegio.estado === 'suspendido' && <MensajeError>El acceso del colegio está pausado. Comunícate con el colegio.</MensajeError>}
      {error && !resumen && (
        <Hoja>
          <MensajeError>{error}</MensajeError>
          <BotonGrande titulo="Reintentar" icono="refresh" alPulsar={() => void recargar()} />
        </Hoja>
      )}
      {!resumen && !error && <Vacio texto="Cargando…" />}
      {resumen && !hijo && <Hoja><Vacio texto="Tu cuenta aún no tiene hijos vinculados. Comunícate con secretaría del colegio." /></Hoja>}

      {hijo && (
        <>
          <SelectorHijo />
          <RatificacionApp api={api} color={color} alRatificar={() => void recargar()} />

          {hijo.pension && <TarjetaPension alPagar={() => nav.navigate('pagos')} />}

          {hijo.asistencia && asistencia && (
            <Hoja>
              <TituloHoja icono="finger-print" titulo="Asistencia de hoy" derecha={<Insignia texto={asistencia.texto} tono={asistencia.tono} />} />
              <Text style={{ color: c.suave, fontSize: t(15) }}>{asistencia.detalle}</Text>
              <Enlace texto={hijo.asistencia.porcentaje_mes !== null ? `Ver récord del mes (${hijo.asistencia.porcentaje_mes} %)` : 'Ver récord del mes'}
                alPulsar={() => nav.navigate('academico', { seccion: 'asistencia' })} />
            </Hoja>
          )}

          {resumen?.modulos.includes('CAL') && (
            <Hoja>
              <TituloHoja icono="reader-outline" titulo="Notas recientes" />
              {hijo.notas_recientes.length === 0 && <Vacio texto="Aún no hay notas registradas este periodo." />}
              {hijo.notas_recientes.map((n) => (
                <View key={n.id} style={{ flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: c.suaveAcento, borderRadius: 12, padding: 12 }}>
                  <View style={{ flex: 1, gap: 2 }}>
                    <Text style={{ color: c.texto, fontSize: t(16), fontWeight: '700' }}>{n.area}</Text>
                    <Text style={{ color: c.texto, fontSize: t(14) }} numberOfLines={1}>{n.actividad}</Text>
                    <Text style={{ color: c.suave, fontSize: t(13) }}>Prof. {n.docente}</Text>
                  </View>
                  <Insignia texto={n.valor} tono={tonoNota(n.valor)} />
                </View>
              ))}
              {hijo.libreta ? (
                <BotonGrande titulo={`Ver libreta · ${hijo.libreta.periodo}`} icono="document-outline"
                  alPulsar={() => abrirLibreta(api, hijo.libreta!.id).catch((e) => setErrorAccion(e instanceof Error ? e.message : 'No se pudo abrir la libreta.'))} />
              ) : null}
              <Enlace texto="Ver todas las notas" alPulsar={() => nav.navigate('academico', { seccion: 'notas' })} />
            </Hoja>
          )}

          {hijo.tareas_pendientes > 0 && (
            <Pressable accessibilityRole="button" onPress={() => nav.navigate('academico', { seccion: 'tareas' })}>
              <Hoja estilo={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                <Ionicons name="clipboard-outline" size={24} color={c.acento} />
                <Text style={{ flex: 1, color: c.texto, fontSize: t(16), fontWeight: '600' }}>
                  {hijo.tareas_pendientes} {hijo.tareas_pendientes === 1 ? 'tarea pendiente' : 'tareas pendientes'}
                </Text>
                <Ionicons name="chevron-forward" size={20} color={c.suave} />
              </Hoja>
            </Pressable>
          )}
        </>
      )}

      {comunicado && (
        <Hoja>
          <TituloHoja icono="megaphone-outline" titulo="Comunicado importante" derecha={!comunicado.leido_at ? <Insignia texto="Nuevo" tono="mal" /> : undefined} />
          <View style={{ backgroundColor: c.suaveAcento, borderRadius: 12, padding: 14, gap: 6 }}>
            <Text style={{ color: c.suave, fontSize: t(13) }}>{fechaLarga(comunicado.enviado_at)}</Text>
            <Text style={{ color: c.texto, fontSize: t(17), fontWeight: '700' }}>{comunicado.titulo}</Text>
            <Text style={{ color: c.texto, fontSize: t(15), lineHeight: t(22) }} numberOfLines={5}>{comunicado.contenido}</Text>
          </View>
          {comunicado.requiere_confirmacion && !comunicado.confirmado_at ? (
            <BotonGrande titulo="Confirmar lectura" icono="checkmark" cargando={confirmando} alPulsar={async () => {
              setConfirmando(true);
              try {
                await api.pedir(`/familia/comunicados/${comunicado.id}`, { metodo: 'POST', cuerpo: { confirmar: true } });
                await recargar();
              } catch (e) {
                setErrorAccion(e instanceof Error ? e.message : 'No se pudo confirmar.');
              } finally {
                setConfirmando(false);
              }
            }} />
          ) : null}
          <Enlace texto={hijo && hijo.comunicados_sin_leer > 1 ? `Ver comunicados (${hijo.comunicados_sin_leer} sin leer)` : 'Ver comunicados'} alPulsar={() => nav.navigate('avisos')} />
        </Hoja>
      )}
      <MensajeError>{errorAccion}</MensajeError>

      {hijo && servicios.length > 0 && (
        <View style={{ gap: 10 }}>
          <Text style={{ color: c.texto, fontSize: t(18), fontWeight: '700' }} accessibilityRole="header">Servicios del colegio</Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
            {servicios.map((s) => <Acceso key={s.clave} icono={s.icono as Icono} titulo={s.titulo} alPulsar={() => nav.navigate('servicio', { clave: s.clave })} />)}
          </View>
        </View>
      )}
    </Pantalla>
  );
}

function TarjetaPension({ alPagar }: { alPagar: () => void }) {
  const { hijo } = useFamilia();
  const { c, t } = useTema();
  const p = hijo?.pension;
  if (!p) return null;
  const estado = estadoPension(p);
  if (!p.proximo) {
    return (
      <Hoja estilo={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
        <Ionicons name="checkmark-circle" size={26} color={TONOS.bien.texto} />
        <Text style={{ flex: 1, color: c.texto, fontSize: t(16), fontWeight: '600' }}>Sin pagos pendientes</Text>
      </Hoja>
    );
  }
  const tono: Tono = estado.tono;
  return (
    <Hoja>
      <View style={{ flexDirection: 'row', gap: 12 }}>
        <View style={{ flex: 1, gap: 4 }}>
          {tono !== 'bien' && (
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
              <Ionicons name="alert-circle" size={16} color={TONOS[tono].texto} />
              <Text style={{ color: TONOS[tono].texto, fontSize: t(13), fontWeight: '700', textTransform: 'uppercase' }}>{estado.texto}</Text>
            </View>
          )}
          <Text style={{ color: c.texto, fontSize: t(19), fontWeight: '700' }}>{p.proximo.descripcion}</Text>
        </View>
        <View style={{ alignItems: 'flex-end' }}>
          <Text style={{ color: c.suave, fontSize: t(13) }}>Por pagar</Text>
          <Text style={{ color: c.acento, fontSize: t(26), fontWeight: '800' }}>{soles(p.proximo.saldo)}</Text>
        </View>
      </View>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: c.suaveAcento, borderRadius: 10, padding: 10 }}>
        <Ionicons name="calendar-outline" size={18} color={c.suave} />
        <Text style={{ flex: 1, color: c.texto, fontSize: t(15) }}>Vence: <Text style={{ fontWeight: '700' }}>{fechaLarga(p.proximo.vencimiento)}</Text></Text>
        <Text style={{ color: TONOS[tono].texto, fontSize: t(14), fontWeight: '600' }}>{tono === 'bien' ? 'Al día' : ''}</Text>
      </View>
      {p.deuda_vencida > 0 && <Text style={{ color: TONOS.mal.texto, fontSize: t(14), fontWeight: '600' }}>Deuda vencida: {soles(p.deuda_vencida)}</Text>}
      <BotonGrande titulo="Pagar en línea" icono="card-outline" alPulsar={alPagar} />
    </Hoja>
  );
}

// ── Pagos: estado de cuenta del hijo, pagar en línea, convenios y tienda.

export function PagosFamilia() {
  const { api, color, colegio } = useApp();
  const { hijo } = useFamilia();
  const { c, t } = useTema();
  return (
    <Pantalla cabecera={<CabeceraFamilia titulo="Pagos" />} refresco={useRefresco()}>
      <SelectorHijo compacto />
      <Hoja>
        <TituloHoja icono="wallet-outline" titulo="Estado de cuenta" />
        <Text style={{ color: c.suave, fontSize: t(14) }}>Marca los cargos que quieres pagar. El pago en línea se confirma al instante y la boleta llega aquí.</Text>
        <EstadoCuentaApp key={hijo?.id} api={api} color={color} alumnoId={hijo?.id} />
      </Hoja>
      <ConveniosApp api={api} color={color} />
      {colegio.modulos?.includes('TIE') && <TiendaApp api={api} color={color} />}
    </Pantalla>
  );
}

// ── Académico: notas y libreta, asistencia del mes y justificar, tareas y horario.

export function AcademicoFamilia() {
  const ruta = useRoute<RouteProp<PestanasParams, 'academico'>>();
  const { api, color, colegio } = useApp();
  const { hijo } = useFamilia();
  const [seccion, setSeccion] = useState<SeccionAcademico>(ruta.params?.seccion ?? 'notas');
  useEffect(() => {
    if (ruta.params?.seccion) setSeccion(ruta.params.seccion);
  }, [ruta.params?.seccion]);
  const opciones: { valor: SeccionAcademico; texto: string }[] = [
    { valor: 'notas', texto: 'Notas' },
    { valor: 'asistencia', texto: 'Asistencia' },
    ...(colegio.modulos?.includes('AUL') !== false ? [{ valor: 'tareas' as const, texto: 'Tareas' }] : []),
    { valor: 'horario', texto: 'Horario' },
  ];
  return (
    <Pantalla cabecera={<CabeceraFamilia titulo="Académico" />} refresco={useRefresco()}>
      <SelectorHijo compacto />
      <Segmentos opciones={opciones} valor={seccion} alCambiar={setSeccion} />
      {hijo && seccion === 'notas' && (
        <>
          <NotasPeriodo key={hijo.id} alumnoId={hijo.id} />
          <NotasApp key={`l${hijo.id}`} api={api} color={color} alumnoId={hijo.id} vacio="Las libretas aparecen aquí cuando el colegio las publica." />
        </>
      )}
      {hijo && seccion === 'asistencia' && (
        <>
          <AsistenciaMes alumnoId={hijo.id} />
          <JustificarApp api={api} color={color} />
        </>
      )}
      {hijo && seccion === 'tareas' && <TareasHijosApp key={hijo.id} api={api} alumnoId={hijo.id} vacio="No hay tareas recientes." />}
      {hijo && seccion === 'horario' && <HorarioHijo alumnoId={hijo.id} />}
    </Pantalla>
  );
}

type NotasDatos = { periodos: { id: number; nombre: string }[]; periodo: { id: number; nombre: string } | null; cursos: { area: string; docente: string; notas: { actividad: string; valor: string; comentario: string | null; fecha: string | null }[] }[] };

/** Notas de las actividades del periodo por curso, con el periodo a elegir. */
function NotasPeriodo({ alumnoId }: { alumnoId: number }) {
  const { api } = useApp();
  const { c, t } = useTema();
  const [periodo, setPeriodo] = useState<number | null>(null);
  const [datos, setDatos] = useState<NotasDatos | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    setDatos(null);
    api.pedir<NotasDatos>(`/familia/notas-periodo?alumno_id=${alumnoId}${periodo ? `&periodo_id=${periodo}` : ''}`).then((d) => { setDatos(d); setError(null); })
      .catch((e) => setError(e instanceof Error ? e.message : 'No se pudieron cargar las notas.'));
  }, [api, alumnoId, periodo]);
  return (
    <Hoja>
      <TituloHoja icono="reader-outline" titulo="Notas del periodo" />
      <MensajeError>{error}</MensajeError>
      {!datos && !error && <Vacio texto="Cargando…" />}
      {datos && datos.periodos.length > 1 && (
        <Segmentos opciones={datos.periodos.map((p) => ({ valor: String(p.id), texto: p.nombre }))} valor={String(datos.periodo?.id ?? '')} alCambiar={(v) => setPeriodo(Number(v))} />
      )}
      {datos && datos.cursos.length === 0 && <Vacio texto="Aún no hay notas registradas en este periodo." />}
      {datos?.cursos.map((k) => (
        <View key={k.area} style={{ gap: 6, borderTopWidth: 1, borderColor: c.borde, paddingTop: 10 }}>
          <Text style={{ color: c.texto, fontSize: t(16), fontWeight: '700' }}>{k.area}</Text>
          <Text style={{ color: c.suave, fontSize: t(13) }}>Prof. {k.docente}</Text>
          {k.notas.map((n, i) => (
            <View key={i} style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
              <View style={{ flex: 1 }}>
                <Text style={{ color: c.texto, fontSize: t(15) }}>{n.actividad}</Text>
                {n.comentario ? <Text style={{ color: c.suave, fontSize: t(13) }}>{n.comentario}</Text> : null}
              </View>
              <Insignia texto={n.valor} tono={tonoNota(n.valor)} />
            </View>
          ))}
        </View>
      ))}
    </Hoja>
  );
}

type AsistenciaDatos = { mes: string; dias: { fecha: string; estado: string; hora: string | null }[]; resumen: Record<'P' | 'T' | 'F' | 'J', number>; porcentaje: number | null };
const TONO_DIA: Record<string, Tono> = { P: 'bien', T: 'atencion', F: 'mal', J: 'neutro' };
const NOMBRE_ESTADO: Record<string, string> = { P: 'Presente', T: 'Tardanza', F: 'Falta', J: 'Justificada' };

function AsistenciaMes({ alumnoId }: { alumnoId: number }) {
  const { api } = useApp();
  const { c, t } = useTema();
  const [mes, setMes] = useState(hoyIso().slice(0, 7));
  const [datos, setDatos] = useState<AsistenciaDatos | null>(null);
  const [error, setError] = useState<string | null>(null);
  const cargar = useCallback(() => {
    setDatos(null);
    api.pedir<AsistenciaDatos>(`/familia/asistencia?alumno_id=${alumnoId}&mes=${mes}`).then((d) => { setDatos(d); setError(null); })
      .catch((e) => setError(e instanceof Error ? e.message : 'No se pudo cargar la asistencia.'));
  }, [api, alumnoId, mes]);
  useEffect(cargar, [cargar]);
  const esMesActual = mes === hoyIso().slice(0, 7);
  const incidencias = (datos?.dias ?? []).filter((d) => d.estado !== 'P');

  return (
    <Hoja>
      <View style={{ flexDirection: 'row', alignItems: 'center' }}>
        <Pressable accessibilityRole="button" accessibilityLabel="Mes anterior" onPress={() => setMes(moverMes(mes, -1))} hitSlop={10}><Ionicons name="chevron-back" size={24} color={c.acento} /></Pressable>
        <Text style={{ flex: 1, textAlign: 'center', color: c.texto, fontSize: t(17), fontWeight: '700' }}>{nombreMes(mes)}</Text>
        <Pressable accessibilityRole="button" accessibilityLabel="Mes siguiente" disabled={esMesActual} onPress={() => setMes(moverMes(mes, 1))} hitSlop={10}>
          <Ionicons name="chevron-forward" size={24} color={esMesActual ? c.borde : c.acento} />
        </Pressable>
      </View>
      <MensajeError>{error}</MensajeError>
      {!datos && !error && <Vacio texto="Cargando…" />}
      {datos && (
        <>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            {(['P', 'T', 'F', 'J'] as const).map((k) => (
              <View key={k} style={{ flex: 1, alignItems: 'center', backgroundColor: TONOS[TONO_DIA[k]!].fondo, borderRadius: 10, paddingVertical: 8 }}>
                <Text style={{ color: TONOS[TONO_DIA[k]!].texto, fontSize: t(20), fontWeight: '800' }}>{datos.resumen[k]}</Text>
                <Text style={{ color: TONOS[TONO_DIA[k]!].texto, fontSize: t(12) }}>{NOMBRE_ESTADO[k]}</Text>
              </View>
            ))}
          </View>
          {datos.porcentaje !== null && <Text style={{ color: c.texto, fontSize: t(15) }}>Asistencia del mes: <Text style={{ fontWeight: '700' }}>{datos.porcentaje} %</Text></Text>}
          <View accessible accessibilityLabel={`Calendario de asistencia de ${nombreMes(mes)}`}>
            <View style={{ flexDirection: 'row' }}>
              {['L', 'M', 'M', 'J', 'V', 'S', 'D'].map((d, i) => <Text key={i} style={{ flex: 1, textAlign: 'center', color: c.suave, fontSize: t(12), fontWeight: '600' }}>{d}</Text>)}
            </View>
            {calendarioMes(mes, datos.dias).map((semana, i) => (
              <View key={i} style={{ flexDirection: 'row' }}>
                {semana.map((d, j) => {
                  const tono = d?.estado ? TONOS[TONO_DIA[d.estado] ?? 'neutro'] : null;
                  return (
                    <View key={j} style={{ flex: 1, aspectRatio: 1, padding: 3 }}>
                      {d && (
                        <View style={{ flex: 1, borderRadius: 8, alignItems: 'center', justifyContent: 'center', backgroundColor: tono?.fondo ?? 'transparent' }}>
                          <Text style={{ color: tono?.texto ?? c.suave, fontSize: t(13), fontWeight: tono ? '700' : '400' }}>{d.dia}</Text>
                        </View>
                      )}
                    </View>
                  );
                })}
              </View>
            ))}
          </View>
          {incidencias.length > 0 && (
            <View style={{ gap: 6 }}>
              <Text style={{ color: c.texto, fontSize: t(15), fontWeight: '700' }}>Faltas y tardanzas</Text>
              {incidencias.map((d) => (
                <View key={d.fecha} style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Text style={{ color: c.texto, fontSize: t(14) }}>{fechaLarga(d.fecha)}</Text>
                  <Text style={{ color: TONOS[TONO_DIA[d.estado] ?? 'neutro'].texto, fontSize: t(14), fontWeight: '600' }}>{NOMBRE_ESTADO[d.estado] ?? d.estado}{d.hora ? ` · ${d.hora}` : ''}</Text>
                </View>
              ))}
            </View>
          )}
          {datos.dias.length === 0 && <Vacio texto="No hay registros de asistencia en este mes." />}
        </>
      )}
    </Hoja>
  );
}

function HorarioHijo({ alumnoId }: { alumnoId: number }) {
  const { api } = useApp();
  const { c, t } = useTema();
  const [horario, setHorario] = useState<Horario | null | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [dia, setDia] = useState(diaInicialHorario());
  useEffect(() => {
    setHorario(undefined);
    api.pedir<{ horario: Horario | null }>(`/familia/horario?alumno_id=${alumnoId}`).then((r) => setHorario(r.horario))
      .catch((e) => { setHorario(null); setError(e instanceof Error ? e.message : 'No se pudo cargar el horario.'); });
  }, [api, alumnoId]);
  if (horario === undefined) return <Vacio texto="Cargando…" />;
  if (!horario) return <Hoja><MensajeError>{error}</MensajeError>{!error && <Vacio texto="Aún no hay horario publicado para su sección." />}</Hoja>;
  const dias = Object.entries(horario.dias).map(([n, nombre]) => ({ valor: n, texto: nombre.slice(0, 3) }));
  const clases = clasesDelDia(horario, dia);
  const ahora = new Date();
  const horaActual = `${String(ahora.getHours()).padStart(2, '0')}:${String(ahora.getMinutes()).padStart(2, '0')}`;
  const esHoy = ahora.getDay() === dia;
  return (
    <Hoja>
      <TituloHoja icono="time-outline" titulo={horario.titulo} />
      <Segmentos opciones={dias} valor={String(dia)} alCambiar={(v) => setDia(Number(v))} />
      {clases.length === 0 && <Vacio texto="No hay clases este día." />}
      {clases.map((k, i) => {
        const enCurso = esHoy && !k.recreo && horaActual >= k.inicio && horaActual < k.fin;
        if (k.recreo) {
          return (
            <View key={i} style={{ flexDirection: 'row', justifyContent: 'space-between', backgroundColor: c.suaveAcento, borderRadius: 10, padding: 10 }}>
              <Text style={{ color: c.texto, fontSize: t(14) }}>Recreo</Text>
              <Text style={{ color: c.suave, fontSize: t(14) }}>{k.inicio} – {k.fin}</Text>
            </View>
          );
        }
        return (
          <View key={i} style={{ borderLeftWidth: 4, borderColor: enCurso ? c.acento : c.borde, paddingLeft: 12, paddingVertical: 4, gap: 2 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
              <Text style={{ color: c.suave, fontSize: t(13) }}>{k.inicio} – {k.fin}</Text>
              {enCurso && <Insignia texto="En curso" tono="bien" />}
            </View>
            <Text style={{ color: c.texto, fontSize: t(17), fontWeight: '700' }}>{k.area}</Text>
            <Text style={{ color: c.suave, fontSize: t(14) }}>Prof. {k.docente}{k.ambiente ? ` · ${k.ambiente}` : ''}</Text>
          </View>
        );
      })}
    </Hoja>
  );
}

// ── Avisos: comunicados del hijo (con confirmación) y agenda del colegio.

export function AvisosFamilia() {
  const { api, color } = useApp();
  const { hijo } = useFamilia();
  return (
    <Pantalla cabecera={<CabeceraFamilia titulo="Avisos" />} refresco={useRefresco()}>
      <SelectorHijo compacto />
      <ComunicadosApp key={hijo?.id} api={api} color={color} alumnoId={hijo?.id} vacio="No hay comunicados para este hijo." />
      <AgendaApp api={api} />
    </Pantalla>
  );
}

// ── Servicio: abre encima de las pestañas la tarjeta del servicio elegido.

export function ServicioFamilia() {
  const ruta = useRoute<RouteProp<RaizParams, 'servicio'>>();
  const nav = useNavigation<Nav>();
  const { api, color } = useApp();
  const { c, t } = useTema();
  const clave: ClaveServicio = ruta.params.clave;
  const contenido: Record<ClaveServicio, ReactElement> = {
    movilidad: <MovilidadFamiliaApp api={api} color={color} />,
    comedor: <MenuComedorApp api={api} />,
    salud: <FichaSaludApp api={api} color={color} />,
    recojo: <RecojoApp api={api} color={color} />,
    autorizaciones: <AutorizacionesApp api={api} color={color} />,
    tramites: <TramitesApp api={api} color={color} />,
    biblioteca: <BibliotecaApp api={api} color={color} />,
  };
  return (
    <Pantalla cabecera={
      <View>
        <Cabecera titulo={tituloServicio(clave)} />
        <Pressable accessibilityRole="button" accessibilityLabel="Volver" onPress={() => nav.goBack()} style={{ flexDirection: 'row', alignItems: 'center', gap: 4, padding: 12, backgroundColor: c.tarjeta }}>
          <Ionicons name="chevron-back" size={20} color={c.acento} />
          <Text style={{ color: c.acento, fontSize: t(15), fontWeight: '600' }}>Volver</Text>
        </Pressable>
      </View>
    }>
      {contenido[clave]}
      <Vacio texto="Si no ves información aquí, el colegio aún no la ha registrado." />
    </Pantalla>
  );
}
