/**
 * @astrake/lumora-webserver — Shared Core ES Module Runtime (lumora-core.js)
 * 1. Hardware & Network Motion Tier Governor (data-motion="full|lite")
 * 2. Accessible Mobile Navigation Drawer & Escape/Focus Trap
 * 3. First-Party Cookie-Free Attribution Capture (sessionStorage)
 * 4. Context-Aware Inquiry Form Validator ("Reward Early, Punish Late")
 * 5. Conditional On-Site Facility Input Toggle & Live Character Counter
 */

const ATTR_STORAGE_KEY = 'lumora_attr_v1';
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

export function initContactForms() {
  const forms = document.querySelectorAll('form[data-lumora-contact-form]');
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

    // "Reward Early, Punish Late" inline field validation
    const validatedInputs = form.querySelectorAll('input[required], textarea[required], select[required]');
    validatedInputs.forEach((input) => {
      const checkField = (showError) => {
        const isValid = input.checkValidity();
        if (isValid) {
          input.setAttribute('aria-invalid', 'false');
          input.classList.remove('is-invalid');
          input.classList.add('is-valid');
        } else if (showError) {
          input.setAttribute('aria-invalid', 'true');
          input.classList.remove('is-valid');
          input.classList.add('is-invalid');
        }
      };

      input.addEventListener('blur', () => {
        if (input.value.trim() !== '') {
          checkField(true);
        }
      });

      input.addEventListener('input', () => {
        if (input.getAttribute('aria-invalid') === 'true' || input.classList.contains('is-valid')) {
          checkField(false);
        }
      });
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
