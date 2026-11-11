// Tipos y utilidades de la configuración de pagos (PAG-02/03 y facturación).

export type Concepto = {
  id: number;
  codigo: string;
  nombre: string;
  tipo: string;
  obligatorio: boolean;
  monto: number;
  afecto_igv: boolean;
  tipo_comprobante: string;
  activo: boolean;
};

export type Cuota = {
  id?: number;
  concepto_cobro_id: number;
  mes: string;
  emision: string;
  vencimiento: string;
  monto: number;
  con_cargos?: boolean;
};

export type Cronograma = { id: number; nivel: string | null; nombre: string; cuotas: (Cuota & { id: number; numero: number; concepto: string; tipo: string })[] };

export type TipoBeneficio = { id: number; nombre: string; tipo: 'porcentaje' | 'monto'; valor: number; motivo: string; aplica_a: string; activo: boolean };

export type Asignacion = {
  id: number;
  alumno_id: number;
  alumno: string;
  beneficio: string;
  tipo: string;
  valor: number;
  estado: 'pendiente' | 'aprobado' | 'rechazado';
  observacion: string | null;
  sustento: string | null;
  sustento_archivo_id: number | null;
  solicitado_por: string | null;
  aprobado_por: string | null;
  aprobado_at: string | null;
};

export type Proveedor = {
  id: number;
  proveedor: string;
  nombre: string;
  ambiente: 'beta' | 'produccion';
  serie_boleta: string;
  serie_factura: string;
  serie_nota_credito: string | null;
  predeterminado: boolean;
  activo: boolean;
  tiene_credenciales: boolean;
};

export const NIVELES: Record<string, string> = { '': 'General (todos los niveles)', inicial: 'Inicial', primaria: 'Primaria', secundaria: 'Secundaria' };

export const soles = (n: number) => `S/ ${n.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** Último día del mes «AAAA-MM». */
export function finDeMes(mes: string): string {
  const [a, m] = mes.split('-').map(Number);
  const d = new Date(Date.UTC(a, m, 0)).getUTCDate();
  return `${mes}-${String(d).padStart(2, '0')}`;
}

/**
 * Cuotas mensuales de pensión de un año: de marzo a diciembre por defecto, emisión el día 1 y
 * vencimiento el día indicado (o el último del mes si no existe).
 */
export function cuotasMensuales(anio: number, conceptoId: number, monto: number, dia: number, desde = 3, hasta = 12): Cuota[] {
  const cuotas: Cuota[] = [];
  for (let m = desde; m <= hasta; m++) {
    const mes = `${anio}-${String(m).padStart(2, '0')}`;
    const fin = finDeMes(mes);
    const vence = Math.min(dia, Number(fin.slice(-2)));
    cuotas.push({ concepto_cobro_id: conceptoId, mes, emision: `${mes}-01`, vencimiento: `${mes}-${String(vence).padStart(2, '0')}`, monto });
  }
  return cuotas;
}
