# تجهيز بنية النشر — Room Booking System

هذه الملفات قوالب تشغيل وليست إعدادًا جاهزًا لخادم بعينه. لم يتم تشغيل خدمة أو Scheduler أو Queue Worker، ولم يتم تطبيق Migration أو الاتصال بـ Stripe. لا تنسخ القيم الوهمية كما هي إلى خادم متاح للعامة.

## ما كشفته مراجعة التطبيق

- Laravel 12 يعرّف فحص الصحة `/up` في `bootstrap/app.php`. الفحص لا يثبت وحده اتصال قاعدة البيانات أو Redis أو Queue.
- المهام المجدولة حاليًا: `bookings:reminders` و`bookings:expire` كل دقيقة مع `onOneServer()` و`withoutOverlapping(5)`؛ و`refunds:reconcile --limit=100` كل دقيقة مع `withoutOverlapping(2)`. المصالحة تتطلب Redis lock مشتركًا، وقفل العملية الواحدة مدته 900 ثانية.
- `webhooks:prune` موجود بوضع Dry Run افتراضي، لكنه غير مجدول. خيار `--execute` يحذف فقط سجلات Webhook المعالجة الأقدم من فترة الاحتفاظ (الافتراضي 90 يومًا). أبقه يدويًا بعد مراجعة العدد والنسخة الاحتياطية؛ لا تضفه إلى cron بصيغة الحذف التلقائي.
- `SendBookingReminderJob` فريد لكل حجز لمدة ساعتين، ويعيد المحاولة خمس مرات بفواصل `[30, 60, 120, 300]` ثانية. حفظ الإشعار وعلامة الإرسال يجريان في معاملة قاعدة البيانات. كما أن مستمعي أحداث أخرى في التطبيق تستخدم Queue؛ لذلك لا تعتمد على `QUEUE_CONNECTION=sync` في النشر.
- إعدادات Laravel الحالية تجعل Cache الافتراضي `database` وQueue الافتراضي `database`. التطبيق يستخدم أقفال Redis صراحةً للمصالحة، و`onOneServer()` يحتاج مخزن Cache موزعًا مشتركًا. قالب الإنتاج يختار Redis للـCache والـQueue والجلسات، مع ضرورة التأكد من تشغيل امتداد `phpredis` على PHP-FPM والـCLI معًا.
- إعداد Stripe في `config/services.php` يقرأ `STRIPE_SECRET` و`STRIPE_WEBHOOK_SECRET`. لا يقرأ مسار Checkout الحالي `STRIPE_KEY`؛ Checkout مستضاف من الخادم. استخدم Test Mode فقط في Staging، واضبط endpoint HTTPS الفعلي في Stripe Dashboard في مرحلة الإطلاق. لا تضع أسرارًا داخل ملفات إعدادات أو سجلات.
- التطبيق يخزن صور المساحات على قرص `public` (`storage/app/public`) ويحتاج رابط `public/storage` أو تخزينًا مشتركًا مناسبًا. لا تجعل ملفات المشروع أو `.env` متاحة من Document Root.
- المنطقة الزمنية في `config/app.php` مضبوطة حاليًا مباشرةً على `Asia/Gaza`، و`APP_TIMEZONE` غير مستخدم في هذا الملف. لذلك لا تضبط متغيرًا بهذا الاسم وتتوقع أن يغيّر الوقت؛ راجع اعتماد المشروع على هذا التوقيت قبل أي تعديل برمجي منفصل.

## حالة التحقق المعروفة

بحسب تحقق Windows المحلي بتاريخ 2026-11-06 بعد تحديثات Symfony: Laravel 12 وPHP 8.4، و`composer audit` أظهر صفر advisories، ونجحت 162 اختبارات مع صفر فشل واختبار واحد متخطّى و1,235 assertion. الإصدارات المقفلة ذات الصلة هي `symfony/http-foundation`, `symfony/http-kernel`, و`symfony/routing` عند `7.4.23`، و`symfony/yaml` عند `8.1.11`.

هذه نتائج البيئة المحلية وليست تحققًا على Ubuntu. لم يُختبر Nginx أو PHP-FPM أو Supervisor أو Redis أو MySQL أو بناء الأصول على Linux/WSL، ولم يتم نشر التطبيق. أعد فحوص Composer والاختبارات على نسخة Linux معزولة قبل Staging. ملف `package-lock.json` ما زال غير موجود؛ تعذّر إنشاؤه هنا لأن npm registry غير متاح، ولم يُتحقق من `npm ci`.

## متطلبات الخادم

1. Linux مدعوم من مزودك، Nginx، PHP-FPM، Composer، MySQL، Redis، Supervisor، وNode.js/npm لبناء أصول Vite. لم أفترض توزيعة أو أسماء خدمات أو مسارات Socket.
2. استخدم PHP متوافقًا مع `composer.lock`. سجل الاعتماديات الحالي يتضمن مكونات Symfony 8.1 التي تتطلب PHP 8.4 أو أحدث؛ لذلك اعتمد PHP 8.4+ بعد التحقق على صورة الخادم باستخدام `composer check-platform-reqs`، حتى لو كانت قيمة `php` العامة في `composer.json` أوسع.
3. ثبّت امتداد `redis` المتوافق مع إصدار PHP-FPM وCLI، وامتدادات PHP التي يطلبها Composer. تحقق على الخادم بالأوامر `php -v`, `php -m`, `php --ri redis`, و`composer check-platform-reqs`، ثم كرر الفحص لمفسر PHP-FPM نفسه.
4. استخدم MySQL 8 متوافقًا مع مخطط التطبيق، ومستخدم قاعدة محدود الصلاحيات على قاعدة التطبيق. لا تستخدم حساب root من Laravel.
5. خصص Redis موثوقًا مع مصادقة/TLS عند الحاجة، وشبكة غير عامة، وسعة ومراقبة. جميع عقد التطبيق في البيئة الواحدة يجب أن تستخدم مخزن Cache/Redis مشتركًا وبادئة مفاتيح واحدة خاصة بالتطبيق والبيئة كي تعمل الأقفال الموزعة. اضبط `REDIS_PREFIX` بقيمة فريدة لكل بيئة، مثل `room-booking-local:`, و`room-booking-staging:`, و`room-booking-production:`. تتشارك عقد البيئة الواحدة البادئة نفسها، ولا تستخدمها بيئة أخرى. يعزل `REDIS_PREFIX` مفاتيح Redis العامة، بما فيها Queue والجلسات والأقفال؛ أما `CACHE_PREFIX` فيعزل مفاتيح Laravel Cache أيضًا. أرقام قواعد Redis (`REDIS_DB` و`REDIS_CACHE_DB`) طبقة فصل إضافية وليست بديلًا عن بادئة فريدة.

## Environment والأسرار

`deploy/.env.production.example` نموذج آمن بلا مفاتيح أو كلمات مرور. يستخدم `LOG_CHANNEL=daily` و`LOG_DAILY_DAYS=14` لتحديد مدة الاحتفاظ بسجلات Laravel؛ أضف حدًا للسعة أو إرسالًا إلى خدمة سجلات مركزية لأن عدد الأيام وحده لا يضع حدًا لحجم الملفات عند ارتفاع معدل التسجيل. أنشئ ملف البيئة الحقيقي خارج Document Root ومن Secret Manager، واضبط الملكية والصلاحيات كي يقرأه PHP-FPM وعامل Queue فقط. أنشئ `APP_KEY` على الخادم المقصود عبر الإجراء المعتمد؛ لا تعِد توليده بعد بدء استخدام الجلسات أو البيانات المشفرة. إعداد البريد يستخدم `MAIL_SCHEME` الذي يقرأه `config/mail.php`؛ تحقق من أن مزود SMTP يفرض TLS وفق المنفذ والسياسة المختارين.

ابدأ بـStaging منفصل: قاعدة بيانات، Redis prefix، نطاق، بريد، ومفاتيح Stripe Test Mode. إعدادات Stripe الفعلية هي `STRIPE_SECRET` و`STRIPE_WEBHOOK_SECRET`. مفتاح النشر من نوع Test يكون في Staging فقط، ولا تستخدم Live credentials حتى اعتماد إطلاق منفصل. لا تنشئ Endpoint عامًا محليًا أو تستخدم Stripe CLI كبديل لإعداد Webhook إنتاجي.

القالب يوصي بـ`SESSION_DRIVER=redis` لتعدد عقد الويب. يمكن الإبقاء على `database` إذا كان هذا قرار التشغيل، مع التأكد من Migration جدول sessions. اضبط `SESSION_SECURE_COOKIE=true` خلف HTTPS، وراجع `APP_URL` والمنطقة الزمنية وفق موقع التشغيل. لا تخزن `.env` في Git أو ضمن Document Root.

## Nginx وPHP-FPM وHTTPS

القالب `deploy/nginx/room-booking.conf` يخدم مجلد `public` فقط، يحصر تنفيذ PHP في `index.php`، ويرفض المسارات الداخلية والملفات المخفية. يحوّل HTTP إلى اسم النطاق الأساسي الثابت بدل استخدام ترويسة `$host`، ويرفض المضيفات غير المعروفة على HTTP وTLS. استبدل كل ظهور لـ`booking.example.invalid` بمضيفك الأساسي ومسارات المشروع وPHP-FPM Socket وشهادات TLS وفق الخادم. كتلة رفض TLS تستخدم `ssl_reject_handshake` وتتطلب Nginx 1.19.4 أو أحدث. افحص الإعداد قبل إعادة تحميل الخدمة بـ`nginx -t`؛ لم يُجرَ هذا الفحص على Linux بعد.

استخدم TLS 1.2/1.3 وشهادة موثوقة وتجديدًا آليًا مراقبًا. اضبط reverse proxy وLaravel `TrustProxies` فقط وفق بنية موثقة؛ لا تثق بكل proxies. اجعل حد رفع Nginx متسقًا مع تحقق التطبيق (صور حتى 5 MB حاليًا). لا تفعّل `autoindex`.

## صلاحيات الملفات والوسائط

- اجعل ملفات الإصدار مملوكة لمستخدم نشر، وغير قابلة للكتابة من PHP-FPM.
- اسمح لمستخدم PHP-FPM/Queue بالكتابة فقط إلى `storage/` و`bootstrap/cache/` ومجلدات الوسائط المشتركة. لا تستخدم `chmod 777`.
- أنشئ `public/storage` عبر `php artisan storage:link` لكل إصدار/الربط المشترك بحسب استراتيجية النشر. تأكد من أن الرابط لا يكشف ملفات خاصة.
- احتفظ بوسائط المستخدمين خارج الإصدار واجعلها مشتركة بين الإصدارات. النسخ الاحتياطية لقاعدة البيانات لا تحتوي هذه الملفات تلقائيًا.

## Queue Worker وRetries

`deploy/supervisor/room-booking-worker.conf` نموذج لعاملين متوازيين على Queue `default`. ملفات العمال مقيّدة بتدوير Supervisor الداخلي عند 20 MB والاحتفاظ بعشر نسخ. ابدأ بعاملين ثم اضبط العدد حسب زمن الانتظار والموارد ومعدل الرسائل. المهام الطويلة تحتاج قياسًا قبل زيادة العمال.

الإعدادات المقترحة متوافقة: Worker `--timeout=120`، و`REDIS_QUEUE_RETRY_AFTER=180` (أكبر من timeout)، وSupervisor `stopwaitsecs=150` (أكبر من timeout). Jobs تحمل إعدادات المحاولات/backoff الخاصة بها؛ لا تعتبر `--tries=5` بديلًا عنها. بعد كل إصدار نفّذ `php artisan queue:restart` لإخراج العمال تدريجيًا، وراقب حالة Supervisor و`php artisan queue:failed` وأزمنة الانتظار والفشل. لا تستخدم `queue:listen` في الإنتاج.

تحقق قبل التشغيل من وجود جدول `failed_jobs` إذا كان `QUEUE_FAILED_DRIVER=database-uuids`، ومن جدول `jobs` إن اخترت Queue قاعدة البيانات بدل Redis. لا تشغّل Queue على بيئة الاختبار أو العرض باستخدام إعدادات الإنتاج.

## Scheduler والأقفال

شغّل Scheduler على مضيف واحد فقط، مع cron كل دقيقة، مثل:

```cron
* * * * * cd /srv/room-booking-api/current && /usr/bin/php artisan schedule:run >> /var/log/room-booking/scheduler.log 2>&1
```

المسار والمفسر ومجلد السجل أمثلة ويجب استبدالها. `deploy/logrotate/room-booking-scheduler` نموذج تدوير يومي لسجل Scheduler مع الاحتفاظ بأربعة عشر يومًا؛ راجع المسار والمستخدم والمجموعة قبل تثبيته. أنشئ مجلد `/var/log/room-booking` بصلاحيات تسمح لمستخدم Scheduler بالكتابة ولمدير Supervisor بإنشاء سجلات العمال. مثال يحتاج تكييفًا مع حسابات المضيف:

```sh
sudo install -d -o root -g room-booking -m 2770 /var/log/room-booking
sudo install -d -o room-booking -g room-booking -m 2770 /srv/room-booking-api/shared/storage/logs
```

يحتاج مجلد سجلات Laravel المشترك صلاحية كتابة لمستخدمي PHP-FPM وQueue؛ نسّق المجموعة وumask حسب حسابات الخدمة، ولا تستخدم `chmod 777`. `onOneServer()` لا يمنع التكرار عبر عقد مختلفة إلا إذا كانت Cache Locks مشتركة وRedis متاحًا. احتفظ بمهلة التداخل الحالية: خمس دقائق للتذكيرات والانتهاء ودقيقتان للمصالحة. لا تشغّل Scheduler كاملًا من أكثر من مضيف دون مخزن Cache مشترك.

تحقق بعد إعداد Staging بـ`php artisan schedule:list`، ثم اختبر التشغيل والمخرجات في نافذة مراقبة. لم يتم تشغيل هذه الأوامر ضمن هذه المهمة. راقب المهام الفاشلة ومعدل تراكم RefundOperations غير المحسومة. لا تضف `webhooks:prune --execute` إلى Scheduler؛ شغّل Dry Run أولًا، راجع العدد والاحتفاظ والنسخة الاحتياطية، واجعل أي حذف إجراءً منفصلًا معتمدًا.

## نشر إصدار وترحيلات قاعدة البيانات

مدير الحزم المستخدم هو npm: المشروع يحتوي `package.json` وسكربتات Vite، ولا يحتوي حاليًا على lockfile. لذلك **بناء الأصول غير جاهز للنشر القابل للتكرار**. أنشئ `package-lock.json` بمصدر npm موثوق، وراجع الإصدارات المحلولة مقارنةً بإصدارات المشروع المقصودة، ثم تحقق في نسخة معزولة باستخدام `npm ci` قبل تنفيذ الخطوات التالية. نفّذ في Staging أولًا وبنفس PHP والامتدادات وإعدادات Redis/Queue المقصودة:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan config:cache
php artisan view:cache
php artisan migrate:status
```

لا تستخدم `composer setup` في الإنتاج: Script المشروع ينشئ `.env` ويولد مفتاحًا ويشغل كل migrations. قبل migrations:

1. راجع الملفات التي ستُطبق وخطة التوافق/التراجع، وخذ نسخة احتياطية كاملة لقاعدة البيانات ووسائطها.
2. تحقق من قاعدة الاتصال من نفس إعداد الإصدار، ومن اسم قاعدة الهدف صراحةً.
3. اختبر استعادة النسخة إلى قاعدة مستقلة. لا تفترض أن MySQL DDL قابل للتراجع.
4. شغّل migration المعتمدة فقط في نافذة التغيير؛ لا تستخدم `migrate:fresh` أو `db:wipe` أو Seeders.
5. أعد تشغيل العمال بـ`queue:restart`، ثم تحقق من المسارات والصحة والسجلات والمهام.

لا تضف `route:cache` إلى الإجراء حتى يثبت توافق جميع Routes في الإصدار المراد نشره. استخدم إصدارًا ذريًا/قابلًا للعودة، ولا تمسح مجلد الإصدار السابق قبل التحقق. لا تستخدم `composer setup` في الإنتاج لأنه ينشئ `.env` ويولّد مفتاحًا ويشغّل migrations.

## تحقق محلي على Ubuntu/WSL ثم Staging

ابدأ بنسخ العمل الحالية إلى نظام ملفات Linux في WSL، مع استبعاد `.env` والاعتماديات المولّدة. تأكد أن مجلد الوجهة جديد أو فارغ قبل النسخ؛ لا تستخدم `--delete`:

```powershell
wsl --status
wsl -d Ubuntu
```

```sh
mkdir -p ~/src/room-booking-api
rsync -a --exclude='.env' --exclude='vendor/' --exclude='node_modules/' --exclude='public/build/' \
  /mnt/c/laragon/www/room-booking-api/ ~/src/room-booking-api/
cd ~/src/room-booking-api
lsb_release -ds
```

تحقق من توفر الحزم قبل تثبيتها. على Linux افحص `php -v` و`php -m` و`php --ri redis` و`php --ri pdo_mysql`، ثم `composer validate --strict` و`composer check-platform-reqs` و`composer audit`. كرر فحص PHP للـFPM، لا للـCLI فقط. ثبّت التبعيات في النسخة المعزولة، ثم شغّل `npm ci` و`npm run build` بعد التأكد من وجود lockfile صالح. افحص PSR-4 وحالة ملفات المسارات على Linux، ثم شغّل الاختبارات فقط على قاعدة اختبار معزولة وبعد التحقق من `SELECT DATABASE()`.

بعد نجاح تحقق WSL، كرر الفحوص على Staging فعلي: `nginx -t`، والتحقق من إعداد Supervisor، وصلاحيات التخزين ورابط `public/storage`، واتصال MySQL وRedis، وHTTPS، وتدوير سجلات Laravel وSupervisor وScheduler، وحالة Queue وScheduler. تظل أسماء النطاقات والشهادات والمقابس والحزم وسلوك PHP-FPM افتراضات إلى أن تُفحص على المضيف الهدف. لا تشغّل الخدمات أو migrations أو Stripe كجزء من الفحص الساكن.

## نسخ احتياطي واستعادة

استخدم أداة MySQL المعتمدة مع `--single-transaction` لقواعد InnoDB، واحفظ المخطط والبيانات والمشغلات والإجراءات/الأحداث إذا كانت مستخدمة. خزّن بيانات الاعتماد عبر ملف عميل محمي أو مدير أسرار، لا في وسيطات سطر الأوامر أو سجل shell. شفّر النسخة وانقلها إلى تخزين منفصل عن الخادم، واضبط الاحتفاظ والتنبيه على فشل النسخ.

انسخ ملفات الوسائط المشتركة على نحو مستقل ومتسق. اختبر الاستعادة دوريًا في قاعدة/مسار معزول، ثم تحقق من أعداد الجداول والعلاقات وسلامة الملفات. وثّق RPO/RTO واعتماد الاستعادة. لا تستعد فوق قاعدة حية دون موافقة وخطة تحويل واضحة.

## فحوص ما بعد النشر والتنبيه

- اجعل `/up` متاحًا لفحص بسيط عبر Load Balancer، مع حماية تفاصيل الاعتماديات. لا تعرض صفحة تشخيص تكشف أسماء قواعد أو إعدادات أو أسرار.
- أجرِ فحوصًا داخلية منفصلة لاتصال MySQL وRedis، وسلامة Cache Locks، وحالة Supervisor، وآخر تشغيل Scheduler، وفشل Queue، وأخطاء PHP-FPM/Nginx.
- راقب 5xx، استهلاك الذاكرة/المعالج، اتصالات قاعدة البيانات، زمن Queue، فشل البريد، Webhook responses وتكرار/تأخر الأحداث، وعمليات Refund غير المحسومة.
- Webhook Stripe يجب أن يصل إلى HTTPS endpoint `/api/v1/stripe/webhook`، مع signing secret من البيئة المقصودة. راقب التوقيع ونتائج المعالجة دون تسجيل محتوى العميل أو الأسرار.
- اختبر في Staging تسجيل الدخول والصلاحيات والحجز والتوفر والتنبيهات ومسارات Checkout/Webhook/Refund بأدوات Stripe Test Mode فقط بعد موافقات المراحل المخصصة. لا تستخدم صفحة نجاح Checkout كدليل دفع.

## حدود هذه المرحلة

لم يُختبر Nginx أو PHP-FPM على Linux، ولم تُشغّل خدمة أو Queue أو Scheduler، ولم يُنفذ اتصال Stripe أو Migration أو Backup حي. أسماء sockets والخدمات ومسارات الشهادات وحسابات النظام أمثلة غير مؤكدة حتى تُراجع على الخادم المستهدف.
