import { describe, expect, it } from 'vitest';
import { leerFecha } from './fechas';
import { grupoInicial, gruposDe, pestanaDeAviso, pestanasDe, serviciosFamilia, tituloServicio } from './navegacionLogica';
import { calendarioMes, clasesDelDia, diaInicialHorario, estadoAsistencia, estadoPension, fechaDeHoy, fechaLarga, hijoElegido, moverMes, nombreCorto, tonoNota, type Horario } from './inicioFamiliaLogica';

describe('navegación según el rol (S36)', () => {
  it('agrupa los roles y deja elegir el modo a quien tiene varios', () => {
    expect(gruposDe(['apoderado'])).toEqual(['familia']);
    expect(gruposDe(['docente', 'apoderado'])).toEqual(['familia', 'docente']);
    expect(gruposDe(['conductor'])).toEqual(['operacion']);
    expect(gruposDe(['director', 'tesoreria'])).toEqual(['oficina']);
    expect(gruposDe([])).toEqual(['oficina']);
    expect(grupoInicial(['docente', 'apoderado'], 'docente')).toBe('docente');
    expect(grupoInicial(['docente', 'apoderado'], 'alumno')).toBe('familia');
  });

  it('la familia tiene cinco pestañas; sin pagos ni tienda no aparece Pagos', () => {
    expect(pestanasDe('familia', ['PAG', 'CAL'])).toEqual(['inicio', 'pagos', 'academico', 'avisos', 'perfil']);
    expect(pestanasDe('familia', ['CAL'])).toEqual(['inicio', 'academico', 'avisos', 'perfil']);
    expect(pestanasDe('docente', ['CAL'])).toEqual(['inicio', 'perfil']);
  });

  it('un aviso abre su pestaña y lo desconocido abre el inicio', () => {
    const familia = pestanasDe('familia', ['PAG']);
    expect(pestanaDeAviso('pagos', familia)).toBe('pagos');
    expect(pestanaDeAviso('comunicados', familia)).toBe('avisos');
    expect(pestanaDeAviso('notas', familia)).toBe('academico');
    expect(pestanaDeAviso('expedientes', familia)).toBe('inicio');
    expect(pestanaDeAviso('pagos', ['inicio', 'perfil'])).toBe('inicio');
    expect(pestanaDeAviso(undefined, familia)).toBe('inicio');
  });

  it('muestra a la familia solo los servicios de los módulos encendidos', () => {
    expect(serviciosFamilia(['MOV', 'DOC']).map((s) => s.clave)).toEqual(['movilidad', 'autorizaciones', 'tramites']);
    expect(serviciosFamilia(undefined)).toHaveLength(7);
    expect(tituloServicio('recojo')).toBe('Recojo y salidas');
  });
});

describe('inicio de la familia (S36)', () => {
  const pension = (vencimiento: string, vencido = false, deuda = 0) => ({ deuda_vencida: deuda, proximo: { id: 1, descripcion: 'Pensión', vencimiento, saldo: 350, vencido } });

  it('marca la pensión vencida, por vencer o al día', () => {
    expect(estadoPension(null, '2026-11-20')).toEqual({ texto: 'Sin pagos pendientes', tono: 'bien' });
    expect(estadoPension(pension('2026-11-10', true), '2026-11-20').tono).toBe('mal');
    expect(estadoPension(pension('2026-11-30', false, 100), '2026-11-20').tono).toBe('mal');
    expect(estadoPension(pension('2026-11-25'), '2026-11-20')).toEqual({ texto: 'Vence en 5 días', tono: 'atencion' });
    expect(estadoPension(pension('2026-11-21'), '2026-11-20').texto).toBe('Vence en 1 día');
    expect(estadoPension(pension('2026-11-20'), '2026-11-20').texto).toBe('Vence hoy');
    expect(estadoPension(pension('2026-12-15'), '2026-11-20')).toEqual({ texto: 'Al día', tono: 'bien' });
  });

  it('explica la asistencia de hoy', () => {
    expect(estadoAsistencia({ estado: 'P', hora: '07:22' })).toEqual({ texto: 'Presente', detalle: 'Ingresó a las 07:22 h, a tiempo.', tono: 'bien' });
    expect(estadoAsistencia({ estado: 'T', hora: '08:05' }).tono).toBe('atencion');
    expect(estadoAsistencia({ estado: 'F', hora: null }).texto).toBe('Falta');
    expect(estadoAsistencia(null).texto).toBe('Sin registro');
  });

  it('colorea las notas literales y vigesimales', () => {
    expect(['AD', 'A', 'B', 'C', '18', '12', '08', 'x'].map(tonoNota)).toEqual(['bien', 'bien', 'atencion', 'mal', 'bien', 'atencion', 'mal', 'neutro']);
  });

  it('escribe fechas en castellano', () => {
    expect(fechaLarga('2026-11-30', '2026-11-20')).toBe('30 de noviembre');
    expect(fechaLarga('2027-03-01', '2026-11-20')).toBe('1 de marzo de 2027');
    expect(fechaDeHoy(new Date(2026, 10, 26))).toBe('Jueves, 26 de noviembre');
    expect(moverMes('2026-12', 1)).toBe('2027-01');
    expect(moverMes('2026-01', -1)).toBe('2025-12');
  });

  it('recuerda el hijo elegido mientras siga en la lista', () => {
    const hijos = [{ id: 4 }, { id: 9 }];
    expect(hijoElegido(hijos, 9)).toEqual({ id: 9 });
    expect(hijoElegido(hijos, 7)).toEqual({ id: 4 });
    expect(hijoElegido([], 7)).toBeNull();
    expect(nombreCorto({ nombres: 'Valeria Sofía', apellidos: 'Quispe Huamán' })).toBe('Valeria Quispe');
  });

  it('arma el calendario del mes empezando en lunes', () => {
    const semanas = calendarioMes('2026-11', [{ fecha: '2026-11-02', estado: 'P' }, { fecha: '2026-11-03', estado: 'F' }]);
    // Noviembre de 2026 empieza en domingo: seis celdas vacías antes del día 1.
    expect(semanas[0]!.slice(0, 6)).toEqual([null, null, null, null, null, null]);
    expect(semanas[0]![6]).toEqual({ dia: 1, estado: null });
    expect(semanas[1]![0]).toEqual({ dia: 2, estado: 'P' });
    expect(semanas[1]![1]).toEqual({ dia: 3, estado: 'F' });
    expect(semanas.every((s) => s.length === 7)).toBe(true);
  });

  it('lista las clases del día con los recreos', () => {
    const h: Horario = {
      titulo: 'Horario de 3° A',
      dias: { 1: 'Lunes' },
      bloques: [
        { id: 2, orden: 2, hora_inicio: '08:15', hora_fin: '09:00', es_recreo: false },
        { id: 1, orden: 1, hora_inicio: '07:30', hora_fin: '08:15', es_recreo: false },
        { id: 3, orden: 3, hora_inicio: '09:00', hora_fin: '09:20', es_recreo: true },
        { id: 4, orden: 4, hora_inicio: '09:20', hora_fin: '10:05', es_recreo: false },
      ],
      celdas: [
        { dia_semana: 1, bloque_horario_id: 1, area: 'Matemática', docente: 'Ana Torres', ambiente: 'Aula 302' },
        { dia_semana: 2, bloque_horario_id: 2, area: 'Arte', docente: 'Luis', ambiente: null },
      ],
    };
    expect(clasesDelDia(h, 1)).toEqual([
      { inicio: '07:30', fin: '08:15', area: 'Matemática', docente: 'Ana Torres', ambiente: 'Aula 302', recreo: false },
      { inicio: '09:00', fin: '09:20', area: null, docente: null, ambiente: null, recreo: true },
    ]);
    expect(diaInicialHorario(new Date(2026, 10, 22))).toBe(1); // domingo → lunes
    expect(diaInicialHorario(new Date(2026, 10, 20))).toBe(5);
  });
});

describe('fechas de la API', () => {
  it('entiende el formato de PostgreSQL (con espacio y zona corta) además de ISO', () => {
    const iso = '2026-11-19T14:43:00.000Z';
    expect(leerFecha('2026-11-19 14:43:00+00').toISOString()).toBe(iso);
    expect(leerFecha('2026-11-19 09:43:00-05').toISOString()).toBe(iso);
    expect(leerFecha('2026-11-19 14:43:00.123456+00').getTime()).toBe(Date.parse('2026-11-19T14:43:00.123Z'));
    expect(leerFecha('2026-11-19T14:43:00+00:00').toISOString()).toBe(iso);
    expect(leerFecha('2026-11-19T14:43:00Z').toISOString()).toBe(iso);
    expect(leerFecha('2026-11-19 19:43:00+0500').toISOString()).toBe(iso);
    expect(leerFecha('2026-11-19').getUTCDate()).toBe(19);
  });
});
