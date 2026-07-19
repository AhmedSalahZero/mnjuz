import { useI18n } from 'vue-i18n'

/**
 * استخدم نفس ترجمات vue-i18n ($t) داخل الـ script.
 * بديل عن trans من laravel-vue-i18n الذي لا يتصل بترجمات التطبيق.
 * الاستخدام: const trans = useTrans(); ثم trans('مفتاح الترجمة')
 */
export function useTrans() {
  const { t } = useI18n()
  return t
}
