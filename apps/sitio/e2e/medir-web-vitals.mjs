// EDUN-311 · Medición local de Core Web Vitals en condiciones móviles (similar a Lighthouse
// móvil: pantalla de 390 px, CPU 4× más lenta y red 4G lenta). Uso:
//   node e2e/medir-web-vitals.mjs http://localhost:3200/
// Objetivos: LCP < 2,5 s, CLS < 0,1 y tareas largas acotadas (aproximación de INP < 200 ms).
import { chromium, devices } from '@playwright/test';

const url = process.argv[2] ?? 'http://localhost:3200/';
const navegador = await chromium.launch();
const contexto = await navegador.newContext({ ...devices['Pixel 5'] });
const pagina = await contexto.newPage();
const cdp = await contexto.newCDPSession(pagina);
await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
await cdp.send('Network.enable');
await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 });

await pagina.addInitScript(() => {
  window.__vitals = { lcp: 0, cls: 0, tareasLargas: [] };
  new PerformanceObserver((l) => l.getEntries().forEach((e) => (window.__vitals.lcp = e.startTime))).observe({ type: 'largest-contentful-paint', buffered: true });
  new PerformanceObserver((l) => l.getEntries().forEach((e) => !e.hadRecentInput && (window.__vitals.cls += e.value))).observe({ type: 'layout-shift', buffered: true });
  new PerformanceObserver((l) => l.getEntries().forEach((e) => window.__vitals.tareasLargas.push(e.duration))).observe({ type: 'longtask', buffered: true });
});

await pagina.goto(url, { waitUntil: 'load' });
await pagina.waitForTimeout(2000);
await pagina.mouse.wheel(0, 4000);
await pagina.waitForTimeout(1500);
const v = await pagina.evaluate(() => window.__vitals);
const maxTarea = Math.max(0, ...v.tareasLargas);
const tbt = v.tareasLargas.reduce((s, d) => s + Math.max(0, d - 50), 0);

console.log(`LCP ${(v.lcp / 1000).toFixed(2)} s (objetivo < 2,5 s) ${v.lcp < 2500 ? 'OK' : 'NO'}`);
console.log(`CLS ${v.cls.toFixed(3)} (objetivo < 0,1) ${v.cls < 0.1 ? 'OK' : 'NO'}`);
console.log(`Tarea más larga ${Math.round(maxTarea)} ms · bloqueo total ${Math.round(tbt)} ms (aprox. INP < 200 ms) ${maxTarea < 200 ? 'OK' : 'REVISAR'}`);
await navegador.close();
