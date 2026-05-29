/* main.js — page dispatcher.
 *
 * Loaded as <script type="module"> from layout.php. Reads
 * meta[name="app-page"] (exposed as appConfig.page) and dynamically
 * imports the matching page module. Each page module owns its own
 * bindings + initial fetches; lib/* modules hold shared utilities.
 *
 * PLAN.md Phase 6 — replaces the previous 1400-line monolith.
 */

import { appConfig } from './lib/api.js';

const PAGES = {
  public: () => import('./pages/public.js'),
  songs:  () => import('./pages/public.js'),     // /songs reuses public bindings
  me:     () => import('./pages/public.js'),     // /me also reuses public bindings
  'admin-login':     () => import('./pages/admin-dashboard.js'),
  'admin-dashboard': () => import('./pages/admin-dashboard.js'),
  'admin-content':   () => import('./pages/admin-dashboard.js'),
  'admin-settings':  () => import('./pages/admin-dashboard.js'),
  'admin-songs':     () => import('./pages/admin-songs.js'),
  display:           () => import('./pages/display.js'),
  'super-tenants':   () => import('./pages/super-catalog.js'),
  'super-login':     () => import('./pages/super-catalog.js'),
  'super-catalog':   () => import('./pages/super-catalog.js'),
  signup:            () => import('./pages/signup.js'),
  'signup-accept':   () => import('./pages/signup.js'),
};

const loader = PAGES[appConfig.page] || PAGES.public;
loader().then(mod => {
  if (typeof mod.init === 'function') mod.init();
}).catch(err => {
  // Surface to the console; access log + Sentry on the server side
  // catch the request-level failures.
  console.error(`Failed to load page module for "${appConfig.page}":`, err);
});
