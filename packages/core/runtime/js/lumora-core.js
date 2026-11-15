/**
 * @astrake/lumora-webserver — Shared Core ES Module Runtime (lumora-core.js)
 * 1. Hardware & Network Motion Tier Governor (data-motion="full|lite")
 * 2. Smart Adaptive Color-Scheme Toggle (light dark / light / dark + localStorage sync)
 * 3. Accessible Navigation Drawer (native modal <dialog> + legacy hidden drawer fallback)
 * 4. First-Party Cookie-Free Attribution Capture (sessionStorage)
 * 5. Accessible Inquiry Form Validator (:user-invalid + aria-invalid + aria-describedby + live announcer)
 */

const ATTR_STORAGE_KEY = 'lumora_attr_v1';
const THEME_STORAGE_KEY = 'color-scheme';
const ATTR_KEYS = [
  'utm_source',
  'utm_medium',
  'utm_campaign',
  'utm_term',
  'utm_content',
  'landing_page',
  'referrer',
  'cta_id',
];

export function initLumoraCore() {
  initMotionTier();
  initThemeToggle();
  initNavigationDrawer();
  initFirstPartyAttribution();
  initContactForms();
}

export function initMotionTier() {
  const root = document.documentElement;
  const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
  const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  const saveData = Boolean(conn?.saveData);
  const cores = navigator.hardwareConcurrency || 8;
  const memory = navigator.deviceMemory || 8;

  root.dataset.motion = (reducedMotion || saveData || cores <= 4 || memory <= 4) ? 'lite' : 'full';
}

export function initThemeToggle() {
  if (typeof document === 'undefined' || typeof window === 'undefined') return;
  const meta = document.querySelector('meta[name="color-scheme"]');
  const toggles = document.querySelectorAll('[data-lumora-theme-toggle]');
  if (!meta) return;

  const media = window.matchMedia?.('(prefers-color-scheme: dark)');
  const getSystemScheme = () => (media?.matches ? 'dark' : 'light');

  const readStoredScheme = () => {
    try {
      const v = window.localStorage?.getItem(THEME_STORAGE_KEY);
      return v === 'light' || v === 'dark' ? v : null;
    } catch {
      return null;
    }
  };

  const writeStoredScheme = (val) => {
    try {
      if (!window.localStorage) return;
      if (val === 'light' || val === 'dark') {
        window.localStorage.setItem(THEME_STORAGE_KEY, val);
      } else {
        window.localStorage.removeItem(THEME_STORAGE_KEY);
      }
    } catch {
      // Storage blocked
    }
  };

  const getRenderedScheme = (stored) => {
    if (stored === 'light' || stored === 'dark') return stored;
    const metaVal = meta.getAttribute('content');
    if (metaVal === 'light' || metaVal === 'dark') return metaVal;
    return getSystemScheme();
  };

  const syncUi = (storedOverride) => {
    const stored = storedOverride !== undefined ? storedOverride : readStoredScheme();
    meta.setAttribute('content', stored ?? 'light dark');
    const rendered = getRenderedScheme(stored);
    const nextTarget = rendered === 'dark' ? 'light' : 'dark';
    const label = `Switch to ${nextTarget} color theme`;

    toggles.forEach((btn) => {
      btn.setAttribute('aria-pressed', rendered === 'dark' ? 'true' : 'false');
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
      btn.dataset.themeState = rendered;
    });
  };

  syncUi();

  toggles.forEach((btn) => {
    btn.addEventListener('click', () => {
      const currentRendered = getRenderedScheme(readStoredScheme());
      const target = currentRendered === 'dark' ? 'light' : 'dark';
      const nextStored = target === getSystemScheme() ? null : target;
      writeStoredScheme(nextStored);
      syncUi(nextStored);
    });
  });

  media?.addEventListener?.('change', () => syncUi());
  window.addEventListener('storage', (e) => {
    if (e.key === THEME_STORAGE_KEY) {
      syncUi(e.newValue === 'light' || e.newValue === 'dark' ? e.newValue : null);
    }
  });
}

export function initFirstPartyAttribution() {
  if (typeof window === 'undefined' || !window.sessionStorage) return;

  const clamp = (val) => String(val || '').trim().slice(0, 120);
  let state = {};
  try {
    const raw = window.sessionStorage.getItem(ATTR_STORAGE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') state = parsed;
    }
  } catch {
    state = {};
  }

  try {
    const params = new URLSearchParams(window.location.search || '');
    for (const k of ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']) {
      const v = clamp(params.get(k));
      if (v && !state[k]) state[k] = v;
    }
    if (!state.landing_page && window.location?.pathname) {
      state.landing_page = clamp(window.location.pathname);
    }
    if (!state.referrer && typeof document !== 'undefined' && document.referrer) {
      state.referrer = clamp(document.referrer);
    }
    window.sessionStorage.setItem(ATTR_STORAGE_KEY, JSON.stringify(state));
  } catch {
    // sessionStorage unavailable
  }

  const syncForms = () => {
    const forms = document.querySelectorAll('form[data-lumora-contact-form]');
    let latest = state;
    try {
      const raw = window.sessionStorage.getItem(ATTR_STORAGE_KEY);
      if (raw) latest = JSON.parse(raw) || state;
    } catch {}

    forms.forEach((form) => {
      for (const key of ATTR_KEYS) {
        const val = clamp(latest[key]);
        if (!val) continue;
        const fieldName = `_attr_${key}`;
        let input = form.querySelector(`input[name="${fieldName}"]`);
        if (!input) {
          input = document.createElement('input');
          input.type = 'hidden';
          input.name = fieldName;
          form.appendChild(input);
        }
        input.value = val;
      }
    });
  };

  if (typeof document !== 'undefined') {
    document.addEventListener('click', (e) => {
      const ctaEl = e.target?.closest?.('[data-lumora-cta]');
      if (!ctaEl) return;
      const ctaId = clamp(ctaEl.getAttribute('data-lumora-cta'));
      if (!ctaId) return;
      try {
        const raw = window.sessionStorage.getItem(ATTR_STORAGE_KEY);
        const cur = raw ? JSON.parse(raw) : { ...state };
        cur.cta_id = ctaId;
        window.sessionStorage.setItem(ATTR_STORAGE_KEY, JSON.stringify(cur));
        syncForms();
      } catch {}
    });

    syncForms();
    document.querySelectorAll('form[data-lumora-contact-form]').forEach((form) => {
      form.addEventListener('submit', syncForms);
    });
  }
}

export const initAttributionCapture = initFirstPartyAttribution;

export function initNavigationDrawer() {
  const toggleBtn = document.querySelector('[data-lumora-nav-toggle]');
  const drawer = document.querySelector('[data-lumora-nav-drawer]');
  if (!toggleBtn || !drawer) return;

  const isDialog = drawer.tagName === 'DIALOG' && typeof drawer.showModal === 'function';
  if (isDialog) {
    const closeBtn = drawer.querySelector('[data-lumora-nav-close]');
    const sheet = drawer.querySelector('[data-lumora-nav-sheet]');

    const closeDialog = () => {
      if (drawer.open) drawer.close();
    };

    toggleBtn.addEventListener('click', () => {
      if (drawer.open) {
        closeDialog();
      } else {
        drawer.showModal();
        toggleBtn.setAttribute('aria-expanded', 'true');
        document.body.classList.add('is-nav-open');
      }
    });

    closeBtn?.addEventListener('click', closeDialog);

    drawer.addEventListener('click', (e) => {
      if (sheet ? !sheet.contains(e.target) : e.target === drawer) {
        closeDialog();
      }
    });

    drawer.addEventListener('close', () => {
      toggleBtn.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('is-nav-open');
      toggleBtn.focus();
    });

    return;
  }

  const setOpen = (open) => {
    toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    drawer.hidden = !open;
    document.body.classList.toggle('is-nav-open', open);
  };

  toggleBtn.addEventListener('click', () => {
    const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
    setOpen(!isExpanded);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && toggleBtn.getAttribute('aria-expanded') === 'true') {
      setOpen(false);
      toggleBtn.focus();
    }
  });
}

function getPoliteAnnouncer() {
  let el = document.querySelector('[data-lumora-live-announcer]');
  if (!el) {
    el = document.createElement('div');
    el.setAttribute('data-lumora-live-announcer', 'true');
    el.setAttribute('aria-live', 'polite');
    el.className = 'visually-hidden';
    document.body.appendChild(el);
  }
  return el;
}

export function initContactForms() {
  const forms = document.querySelectorAll('form[data-lumora-contact-form]');
  if (forms.length === 0) return;

  const supportsUserInvalid = typeof CSS !== 'undefined' && typeof CSS.supports === 'function'
    ? CSS.supports('selector(:user-invalid)')
    : false;
  const announcer = getPoliteAnnouncer();
  let announceTimer = null;

  const linkError = (el, shown) => {
    const errorId = el.dataset?.error;
    if (!errorId) return;
    const ids = (el.getAttribute('aria-describedby') || '')
      .split(/\s+/)
      .filter((id) => id && id !== errorId);
    if (shown) ids.push(errorId);
    if (ids.length > 0) {
      el.setAttribute('aria-describedby', ids.join(' '));
    } else {
      el.removeAttribute('aria-describedby');
    }
  };

  const announceFieldError = (el) => {
    const errorId = el.dataset?.error;
    if (!errorId) return;
    const errEl = document.getElementById(errorId);
    if (!errEl) return;
    const labelText = el.labels?.[0]?.textContent?.replace(/\s+/g, ' ').trim() || el.name || 'Field';
    const msgText = errEl.textContent?.replace(/\s+/g, ' ').trim() || el.validationMessage || 'Invalid value.';
    clearTimeout(announceTimer);
    announcer.textContent = '';
    announceTimer = setTimeout(() => {
      announcer.textContent = `${labelText}: ${msgText}`;
    }, 350);
  };

  forms.forEach((form) => {
    const modeSelect = form.querySelector('[data-lumora-delivery-mode]');
    const onsiteBox = form.querySelector('[data-lumora-onsite-fields]');
    const locationInput = form.querySelector('[name="facility_location"], [name="city"]');
    const phoneInput = form.querySelector('[name="phone"]');
    const phoneBadge = form.querySelector('[data-lumora-phone-required]');
    const msgInput = form.querySelector('[name="message"]');
    const charCounter = form.querySelector('[data-lumora-char-counter]');

    const syncDeliveryMode = (modeValue) => {
      const normalized = String(modeValue || '').toLowerCase();
      const isOnsite =
        normalized === 'onsite' ||
        normalized === 'in_person' ||
        normalized === 'on-site assessment at our facility';
      if (onsiteBox) onsiteBox.hidden = !isOnsite;
      if (locationInput) locationInput.required = isOnsite;
      if (phoneInput) phoneInput.required = isOnsite;
      if (phoneBadge) phoneBadge.hidden = !isOnsite;
    };

    if (modeSelect) {
      modeSelect.addEventListener('change', (e) => syncDeliveryMode(e.target.value));
      syncDeliveryMode(modeSelect.value);
    }

    const modeBtns = form.querySelectorAll('[data-lumora-mode-btn]');
    modeBtns.forEach((btn) => {
      btn.addEventListener('click', () => {
        const mode = btn.getAttribute('data-lumora-mode-btn') || 'online';
        const hiddenInput = form.querySelector(
          'input[name="consultation_mode"], input[name="delivery_mode"], input[name="demo_format"]'
        );
        if (hiddenInput) hiddenInput.value = mode;
        modeBtns.forEach((b) => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
        syncDeliveryMode(mode);
      });
    });

    const controls = Array.from(form.querySelectorAll('input, select, textarea')).filter(
      (el) => el.type !== 'hidden' && el.name !== 'website_url'
    );
    const interacted = new WeakSet();
    const serverInvalid = new WeakSet();
    const announced = new WeakSet();

    controls.forEach((el) => {
      if (el.getAttribute('aria-invalid') === 'true') {
        serverInvalid.add(el);
        linkError(el, true);
      } else {
        el.setAttribute('aria-invalid', 'false');
      }
    });

    const syncFieldState = (el, forceShow = false, shouldAnnounce = false) => {
      if (!el || typeof el.checkValidity !== 'function') return true;
      if (serverInvalid.has(el) && !interacted.has(el)) {
        return false;
      }

      let isInvalid = false;
      if (forceShow) {
        isInvalid = !el.validity.valid;
      } else if (supportsUserInvalid) {
        try {
          isInvalid = el.matches(':user-invalid');
        } catch {
          isInvalid = interacted.has(el) && !el.validity.valid;
        }
      } else {
        isInvalid = interacted.has(el) && !el.validity.valid;
      }

      if (isInvalid) {
        el.setAttribute('aria-invalid', 'true');
        el.classList.remove('is-valid');
        el.classList.add('is-invalid');
        linkError(el, true);
        if (shouldAnnounce && !announced.has(el)) {
          announceFieldError(el);
          announced.add(el);
        }
        return false;
      }

      el.setAttribute('aria-invalid', 'false');
      el.classList.remove('is-invalid');
      if (interacted.has(el) && el.validity.valid && (el.required || String(el.value || '').trim() !== '')) {
        el.classList.add('is-valid');
      } else {
        el.classList.remove('is-valid');
      }
      linkError(el, false);
      announced.delete(el);
      return true;
    };

    form.addEventListener('blur', (e) => {
      const el = e.target;
      if (!controls.includes(el)) return;
      interacted.add(el);
      const hasContent = el.type === 'checkbox' || el.tagName === 'SELECT' || String(el.value || '').trim() !== '';
      syncFieldState(el, hasContent && !el.validity.valid, true);
    }, true);

    form.addEventListener('input', (e) => {
      const el = e.target;
      if (!controls.includes(el)) return;
      interacted.add(el);
      serverInvalid.delete(el);
      if (el.getAttribute('aria-invalid') === 'true' || el.classList.contains('is-invalid') || el.classList.contains('is-valid')) {
        syncFieldState(el, !el.validity.valid && el.classList.contains('is-invalid'), false);
      }
    });

    form.addEventListener('change', (e) => {
      const el = e.target;
      if (!controls.includes(el)) return;
      interacted.add(el);
      serverInvalid.delete(el);
      syncFieldState(el, !el.validity.valid, true);
    }, true);

    form.addEventListener('invalid', (e) => {
      const el = e.target;
      if (!controls.includes(el)) return;
      interacted.add(el);
      announced.add(el);
      syncFieldState(el, true, false);
    }, true);

    form.addEventListener('reset', () => {
      setTimeout(() => {
        controls.forEach((el) => {
          interacted.delete(el);
          serverInvalid.delete(el);
          announced.delete(el);
          el.setAttribute('aria-invalid', 'false');
          el.classList.remove('is-invalid', 'is-valid');
          linkError(el, false);
        });
      });
    });

    form.addEventListener('submit', (e) => {
      if (!form.noValidate) return;
      let firstInvalid = null;
      controls.forEach((el) => {
        if (typeof el.checkValidity !== 'function') return;
        if (!el.validity.valid) {
          interacted.add(el);
          announced.add(el);
          syncFieldState(el, true, false);
          if (!firstInvalid) firstInvalid = el;
        }
      });
      if (firstInvalid) {
        e.preventDefault();
        firstInvalid.focus();
      }
    });

    if (msgInput && charCounter) {
      const max = Number(msgInput.getAttribute('maxlength') || 4000);
      const min = Number(msgInput.getAttribute('minlength') || 15);
      const updateCounter = () => {
        const len = msgInput.value.length;
        charCounter.textContent = `${len} / ${max} CHARS · MIN ${min}`;
        charCounter.classList.toggle('is-ready', len >= min);
      };
      msgInput.addEventListener('input', updateCounter);
      updateCounter();
    }
  });
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLumoraCore, { once: true });
  } else {
    initLumoraCore();
  }
}
