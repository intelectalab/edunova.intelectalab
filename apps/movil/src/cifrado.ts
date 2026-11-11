// EDUN-197 · Cifrado de la cola local. Lo pendiente de enviar (asistencia, lecturas de carné)
// queda en el teléfono cifrado con XChaCha20-Poly1305; la clave vive en el llavero seguro del
// sistema (SecureStore), nunca junto a los datos. Lógica pura: la clave y el azar se inyectan.
import { xchacha20poly1305 } from '@noble/ciphers/chacha.js';
import { bytesToHex, bytesToUtf8, hexToBytes, utf8ToBytes } from '@noble/ciphers/utils.js';

export interface Cifrado {
  cifrar(texto: string): string;
  descifrar(texto: string): string;
}

const PREFIJO = 'x1:';

export function crearCifrado(clave: Uint8Array, aleatorio: (n: number) => Uint8Array): Cifrado {
  if (clave.length !== 32) throw new Error('La clave debe tener 32 bytes.');
  return {
    cifrar(texto) {
      const nonce = aleatorio(24);
      return PREFIJO + bytesToHex(nonce) + bytesToHex(xchacha20poly1305(clave, nonce).encrypt(utf8ToBytes(texto)));
    },
    descifrar(texto) {
      if (!texto.startsWith(PREFIJO)) throw new Error('Formato desconocido.');
      const crudo = hexToBytes(texto.slice(PREFIJO.length));
      return bytesToUtf8(xchacha20poly1305(clave, crudo.slice(0, 24)).decrypt(crudo.slice(24)));
    },
  };
}
