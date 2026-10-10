/**
 * Stamps clarity-scout-sw.js with a build timestamp.
 *
 * Run after `vite build` so the service worker file changes byte-for-byte
 * on every deploy, triggering the browser's SW update check.
 */

import { readFileSync, writeFileSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const swPath = resolve(__dirname, '../public/clarity-scout-sw.js');

const timestamp = Date.now().toString();
let content = readFileSync(swPath, 'utf-8');

content = content.replace(
    /const APP_VERSION = '.*?';/,
    `const APP_VERSION = '${timestamp}';`
);

writeFileSync(swPath, content, 'utf-8');
console.log(`[version-sw] Stamped clarity-scout-sw.js with version: ${timestamp}`);
