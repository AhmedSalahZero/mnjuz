import { createInertiaApp } from '@inertiajs/vue3'
import axios from 'axios'
import { createApp, h, watchEffect } from 'vue'
import { createI18n } from 'vue-i18n'
import VueTelInput from 'vue-tel-input'
import VueApexCharts from 'vue3-apexcharts'

const I18N_CACHE_KEY = 'mnjuz_i18n_bootstrap';
const I18N_TRANSLATIONS_PREFIX = 'mnjuz_i18n_translations_';
const CACHE_TTL_MS = 24 * 60 * 60 * 1000; // 24 ساعة

function getCachedBootstrap() {
  try {
    const raw = localStorage.getItem(I18N_CACHE_KEY);
    if (!raw) return null;
    const { locale, locales, translations, at } = JSON.parse(raw);
    if (at && Date.now() - at > CACHE_TTL_MS) return null;
    return { locale, locales, translations };
  } catch {
    return null;
  }
}

function setCachedBootstrap(data) {
  try {
    localStorage.setItem(I18N_CACHE_KEY, JSON.stringify({
      ...data,
      at: Date.now(),
    }));
  } catch (_) {}
}

function getCachedTranslations(locale) {
  try {
    const raw = localStorage.getItem(I18N_TRANSLATIONS_PREFIX + locale);
    if (!raw) return null;
    const { messages, at } = JSON.parse(raw);
    if (at && Date.now() - at > CACHE_TTL_MS) return null;
    return messages;
  } catch {
    return null;
  }
}

function setCachedTranslations(locale, messages) {
  try {
    localStorage.setItem(I18N_TRANSLATIONS_PREFIX + locale, JSON.stringify({
      messages,
      at: Date.now(),
    }));
  } catch (_) {}
}

/** حذف كاش اللغة والترجمات (يُستدعى عند تغيير اللغة من الـ profile) */
function clearI18nCache() {
  try {
    localStorage.removeItem(I18N_CACHE_KEY);
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const k = localStorage.key(i);
      if (k && k.startsWith(I18N_TRANSLATIONS_PREFIX)) localStorage.removeItem(k);
    }
  } catch (_) {}
}
if (typeof window !== 'undefined') window.clearI18nCache = clearI18nCache;

/** طلب واحد فقط: /bootstrap-locale يعيد locale + locales + translations */
async function fetchBootstrapLocale() {
  const response = await axios.get('/bootstrap-locale');
  return response.data;
}

async function loadLocaleMessages(locale) {
  const cached = getCachedTranslations(locale);
  if (cached) return cached;
  const response = await axios.get(`/translations/${locale}`);
  const messages = response.data;
  setCachedTranslations(locale, messages);
  return messages;
}

createInertiaApp({
  resolve: async (name) => {
    const pages = import.meta.glob('./Pages/**/*.vue');
    const modulePages = import.meta.glob('../../modules/**/Pages/**/*.vue');
    const [moduleName, pageName] = name.split('::');

    if (pageName) {
      const key = `../../modules/${moduleName}/Pages/${pageName}.vue`;
      const component = modulePages[key];
      if (component) {
        const resolvedComponent = await component();
        return resolvedComponent.default || resolvedComponent;
      }
    }

    const component = pages[`./Pages/${name}.vue`];
    if (component) {
      const resolvedComponent = await component();
      return resolvedComponent.default || resolvedComponent;
    }
    throw new Error(`Page not found: ${name}`);
  },
  setup({ el, App, props, plugin }) {
    (async () => {
      let currentLocale;
      let availableLocales;
      let initialMessages = {};
      const cached = getCachedBootstrap();
      if (cached) {
        currentLocale = cached.locale;
        availableLocales = cached.locales;
        initialMessages = { [currentLocale]: cached.translations };
      } else {
        const data = await fetchBootstrapLocale();
        currentLocale = data.locale;
        availableLocales = data.locales || [];
        initialMessages = { [currentLocale]: data.translations || {} };
        setCachedBootstrap({
          locale: currentLocale,
          locales: availableLocales,
          translations: data.translations || {},
        });
      }
      const i18n = createI18n({
        legacy: false,
        locale: currentLocale,
        fallbackLocale: 'ar',
        messages: initialMessages,
      });
      const app = createApp({ render: () => h(App, props) });
      app.use(plugin)
        .use(VueApexCharts)
        .use(VueTelInput)
        .use(i18n)
        .mount(el);

      watchEffect(async () => {
        const newLocale = i18n.global.locale.value;
        if (!i18n.global.availableLocales.includes(newLocale) && availableLocales.includes(newLocale)) {
          const messages = await loadLocaleMessages(newLocale);
          i18n.global.setLocaleMessage(newLocale, messages);
        }
      });
    })();
  },
  progress: {
    delay: 250,
    color: '#198754',
    includeCSS: true,
    showSpinner: false,
  },
});
