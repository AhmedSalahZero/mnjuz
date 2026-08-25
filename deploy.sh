# العمّال يُوقَفون قبل سحب الكود لا بعده.
#
# عامل الطابور عملية طويلة العمر تُحمّل كل كلاس في ذاكرتها أول مرّة تحتاجه.
# فإن بقي شغّالاً أثناء النشر: يظلّ محتفظاً بالكلاسات القديمة التي حمّلها،
# ويقرأ من القرص — لأول مرّة — الكلاسات الجديدة التي لم يكن يحتاجها بعد.
# فيلتقي في العملية الواحدة كودٌ قديم بكودٍ جديد، ويسقط الاستدعاء بينهما.
#
# وقع هذا فعلاً: Chat::getCreatedAtAttribute الجديدة نادت
# DateTimeHelper::toOrganizationTimeString بينما الـHelper القديم في الذاكرة
# لا يعرفها — فانهارت ProcessIncomingMessageJob وضاع البثّ اللحظي للرسائل
# الواردة (الرسائل نفسها سليمة في القاعدة، وتصل التطبيق في المزامنة التالية).
#
# النافذة الخطرة هي المدّة بين git pull وإعادة التشغيل: تثبيت الحزم
# والترحيلات ومسح الذاكرة المؤقتة — عشرات الثواني يستهلك فيها العمّال
# وظائف بكودٍ نصفه جديد. إيقافهم أولاً يُغلق هذه النافذة.
#
# ملاحظة: stop all يوقف كل ما يديره supervisor طوال النشر — إن كان يدير
# reverb أو غيره ممّا لا يُراد إسقاطه، استبدل all باسم برنامج الطابور وحده.
sudo supervisorctl stop all || true

# شبكة أمان: نشرٌ ينقطع في منتصفه لا يجوز أن يترك الطابور ميّتاً.
trap 'sudo supervisorctl start all >/dev/null 2>&1 || true' EXIT

git status
git stash
git pull origin master
chmod -R 775 storage
chmod -R 775 bootstrap/cache
chmod 777 -R storage/*
echo ">>> Installing dependencies"
$(which php) $(which composer) install --no-interaction --prefer-dist --optimize-autoloader

$(which php) artisan migrate --force
$(which php) artisan optimize:clear

sudo supervisorctl start all
