/**
 * Otto page-context registry.
 *
 * A Filament page adapter (InteractsWithOttoPageActions) advertises its identity
 * + current state here. The Otto popout reads the active registration at
 * send-time and ships it to the backend alongside the prompt.
 *
 * Staleness: the popout persists across SPA page swaps, so each registration is
 * stamped with the path it was made on, and currentPageContext() only reports
 * it while the user is still on that path.
 *
 * Nothing here is trusted as authorization — it is a hint to the assistant only.
 */

const registry = { current: null };

function currentPath() {
  try {
    return window.location.pathname;
  } catch (e) {
    return null;
  }
}

/**
 * Known Otto page adapters keyed by pathname. Used when Livewire has not yet
 * re-registered after a full navigation.
 */
const KNOWN_ADAPTER_PATHS = {
  '/app/scans': {
    pageType: 'scan-search',
    description: 'Scans',
    state: {},
  },
  '/app/hits': {
    pageType: 'hit-search',
    description: 'Hits',
    state: {},
  },
  '/app/hotlists': {
    pageType: 'hotlist-search',
    description: 'Hotlist',
    state: {},
  },
  '/app/hotlist': {
    pageType: 'hotlist-search',
    description: 'Hotlist',
    state: {},
  },
  '/scout/org-map': {
    pageType: 'scout-map',
    description: 'Org Map',
    state: {},
  },
};

function knownAdapterForPath(path) {
  if (!path) {
    return null;
  }
  const normalized = path.replace(/\/$/, '');
  if (KNOWN_ADAPTER_PATHS[normalized]) {
    return KNOWN_ADAPTER_PATHS[normalized];
  }
  if (normalized === '/scout/org-map' || normalized.startsWith('/scout/org-map/')) {
    return {
      pageType: 'scout-map',
      description: 'Org Map',
      state: {},
    };
  }
  return null;
}

function genericPageContext() {
  const path = currentPath();
  const known = knownAdapterForPath(path);
  if (known) {
    let title = '';
    try {
      title = (document.title || '').trim();
    } catch (e) {
      title = '';
    }
    return {
      pageType: known.pageType,
      description: title || known.description,
      state: { ...known.state },
    };
  }

  let title = '';
  try {
    title = (document.title || '').trim();
  } catch (e) {
    title = '';
  }
  const description = title || path || '';
  if (!description && !path) {
    return null;
  }
  return { pageType: path || 'unknown', description, state: {} };
}

function descriptorState(descriptor) {
  try {
    if (typeof descriptor.getState === 'function') {
      return descriptor.getState() || {};
    }
    if (descriptor.state && typeof descriptor.state === 'object') {
      return descriptor.state;
    }
  } catch (e) {
    // A page that throws while reporting its state simply reports none.
  }
  return {};
}

const api = {
  registerPage(descriptor) {
    if (!descriptor || !descriptor.pageType) {
      return;
    }
    registry.current = { ...descriptor, path: currentPath() };
    try {
      window.dispatchEvent(new CustomEvent('otto:page-context-changed'));
    } catch (e) {
      /* CustomEvent unavailable */
    }
  },

  clearPage() {
    registry.current = null;
  },

  currentPageContext() {
    const descriptor = registry.current;
    if (descriptor && descriptor.pageType) {
      if (descriptor.path === null || descriptor.path === currentPath()) {
        return {
          pageType: descriptor.pageType,
          description: descriptor.description || '',
          state: descriptorState(descriptor),
        };
      }
      registry.current = null;
    }
    return genericPageContext();
  },
};

window.Otto = Object.assign(window.Otto || {}, api);

document.addEventListener('livewire:navigate', () => window.Otto.clearPage());
window.addEventListener('popstate', () => window.Otto.clearPage());

export {};
