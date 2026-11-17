import '@testing-library/jest-dom/vitest';
import { cleanup, configure } from '@testing-library/react';
import { afterEach } from 'vitest';

// Las pantallas se cargan con React.lazy: con la máquina ocupada (otras pruebas en paralelo) el
// segundo por defecto de findBy se queda corto y la prueba falla a veces sin motivo.
configure({ asyncUtilTimeout: 5000 });

afterEach(() => cleanup());
