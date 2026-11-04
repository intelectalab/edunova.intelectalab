import tokens from '../tokens.json' with { type: 'json' };

export const color = tokens.color;
export const fuente = tokens.fuente;
export const sombra = tokens.sombra;
export const radio = tokens.radio;

export type ColorToken = keyof typeof tokens.color;

export default tokens;
