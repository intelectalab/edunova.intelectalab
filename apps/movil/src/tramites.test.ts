import { describe, expect, it } from 'vitest';
import { requisitosFaltantes } from './tramitesLogica';

describe('trámites en la app (EDUN-114)', () => {
  const tipo = { requisitos: [{ id: 1, descripcion: 'Copia del DNI', obligatorio: true }, { id: 2, descripcion: 'Foto', obligatorio: false }] };

  it('no deja enviar sin los requisitos obligatorios', () => {
    expect(requisitosFaltantes(tipo, {})).toEqual(['Copia del DNI']);
    expect(requisitosFaltantes(tipo, { 2: 'foto' })).toEqual(['Copia del DNI']);
  });

  it('los opcionales no bloquean el envío', () => {
    expect(requisitosFaltantes(tipo, { 1: 'dni' })).toEqual([]);
    expect(requisitosFaltantes(undefined, {})).toEqual([]);
  });
});
