import { registerRootComponent } from 'expo';
import App from './src/App';
// La tarea de ubicación en segundo plano debe registrarse antes de que arranque la app (EDUN-165).
import './src/ubicacion';

registerRootComponent(App);
