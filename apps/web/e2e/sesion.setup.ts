import { test as preparar } from '@playwright/test';
import { confirmarSegundoFactor, DIRECTOR, ingresar } from './datos.ts';

// El director ingresa una sola vez (el segundo factor no acepta dos veces el mismo código) y
// las demás pruebas reutilizan su sesión: el token queda en el localStorage de la web.
preparar('el director ingresa con contraseña y segundo factor', async ({ page }) => {
  await ingresar(page, DIRECTOR.email);
  await confirmarSegundoFactor(page);
  await page.context().storageState({ path: 'e2e/.sesion/director.json' });
});
