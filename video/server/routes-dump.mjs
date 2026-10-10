import { buildApp } from './src/app.js';
const app = await buildApp({ logger: false });
await app.ready();
console.log(app.printRoutes({ commonPrefix: false }));
await app.close();
process.exit(0);
