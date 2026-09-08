<?php

declare(strict_types=1);

/*
 * Notification copy, one whole sentence per message per language.
 *
 * Never assembled from fragments. "Your " . $kind . " is ready" is a sentence
 * no translator can repair: Arabic orders the words differently and inflects
 * around them, so the pieces have no correct translation in isolation.
 *
 * Placeholders are named rather than positional for the same reason - the
 * amount and the invoice number appear in a different order in the two
 * languages, and :amount is the only form that survives being moved.
 *
 * Nested by group so that Laravel's dot lookup reaches them: a top-level key
 * containing a literal dot is unreachable, because the translator splits the
 * key it is given and never finds a match.
 */

return [

    'security' => [
        'password_changed' => [
            'title' => 'تم تغيير كلمة المرور',
            'body' => 'تم تغيير كلمة مرور حسابك في Lynomia Cloud. إن لم تكن أنت من فعل ذلك، أعد تعيينها فورًا وراجع جلساتك النشطة.',
        ],
        'two_factor_enabled' => [
            'title' => 'تم تفعيل المصادقة الثنائية',
            'body' => 'المصادقة الثنائية مفعّلة الآن على حسابك. احتفظ برموز الاسترداد في مكان آمن.',
        ],
        'two_factor_disabled' => [
            'title' => 'تم تعطيل المصادقة الثنائية',
            'body' => 'تم إيقاف المصادقة الثنائية على حسابك. إن لم تكن أنت من فعل ذلك، أعد تفعيلها وغيّر كلمة المرور.',
        ],
        'new_sign_in' => [
            'title' => 'تسجيل دخول جديد إلى حسابك',
            'body' => 'تم تسجيل الدخول إلى حسابك من :location. إن لم تكن أنت، غيّر كلمة المرور وأنهِ تلك الجلسة.',
        ],
    ],
    'billing' => [
        'order_placed' => [
            'title' => 'استلمنا الطلب :number',
            'body' => 'استلمنا طلبك وسنبدأ التنفيذ فور سداده.',
        ],
        'invoice_issued' => [
            'title' => 'فاتورة :number بمبلغ :amount',
            'body' => 'صدرت الفاتورة :number بمبلغ :amount وتستحق في :due_date.',
        ],
        'payment_succeeded' => [
            'title' => 'استلمنا دفعة بمبلغ :amount',
            'body' => 'شكرًا لك. استلمنا دفعتك بمبلغ :amount وطبّقناها على الفاتورة :number.',
        ],
        'payment_failed' => [
            'title' => 'تعذّر تحصيل مبلغ :amount',
            'body' => 'لم نتمكن من تحصيل مبلغ :amount للفاتورة :number. يرجى مراجعة بيانات بطاقتك؛ وسنعيد المحاولة تلقائيًا.',
        ],
        'refund_issued' => [
            'title' => 'تم إصدار استرداد بمبلغ :amount',
            'body' => 'تم إصدار استرداد بمبلغ :amount إلى وسيلة الدفع الأصلية. قد يستغرق ظهوره عدة أيام عمل.',
        ],
        'renewal_upcoming' => [
            'title' => 'يتجدد اشتراكك في :date',
            'body' => 'يتجدد :service في :date بمبلغ :amount. لا يلزمك أي إجراء إن كانت بيانات الدفع محدّثة.',
        ],
        'renewal_succeeded' => [
            'title' => 'تم تجديد الاشتراك',
            'body' => 'تم تجديد :service لمدة جديدة. دفعتك القادمة تستحق في :date.',
        ],
        'renewal_failed' => [
            'title' => 'تعذّر تجديد اشتراكك',
            'body' => 'لم نتمكن من تحصيل قيمة تجديد :service. يرجى تحديث بيانات الدفع قبل :grace_ends لتفادي انقطاع الخدمة.',
        ],
        'grace_period_started' => [
            'title' => 'سداد :service متأخر',
            'body' => 'سداد :service متأخر. تستمر خدمتك حتى :grace_ends ثم تُعلَّق.',
        ],
        'suspension_warning' => [
            'title' => 'سيتم تعليق :service في :date',
            'body' => 'ما زال سداد :service مستحقًا. سيتم تعليقه في :date ما لم نستلم الدفعة.',
        ],
        'cancellation_scheduled' => [
            'title' => 'من المقرر إنهاء :service',
            'body' => 'سينتهي :service في :date. ستحتفظ باستخدامه الكامل حتى ذلك التاريخ. وتُحفظ بياناتك :retention_days يومًا بعد ذلك ثم تُحذف بانقضائها.',
        ],
    ],
    'service' => [
        'provisioning' => [
            'title' => 'جارٍ تجهيز :service',
            'body' => 'بدأنا بناء :service. يستغرق ذلك عادةً بضع دقائق وسنخبرك عند الجاهزية.',
        ],
        'ready' => [
            'title' => ':service جاهز',
            'body' => ':service يعمل الآن وجاهز للاستخدام. يمكنك إدارته من بوابتك.',
        ],
        'provisioning_failed' => [
            'title' => 'تعذّر تجهيز :service',
            'body' => 'حدث خطأ أثناء بناء :service. تم تنبيه فريقنا وسيراجع الأمر؛ ولم تُحمَّل رسومًا مقابل خدمة غير موجودة.',
        ],
        'needs_review' => [
            'title' => ':service يحتاج إلى مراجعتنا',
            'body' => 'لم يكتمل تجهيز :service بصورة سليمة ويحتاج إلى مراجعة بشرية. سنتواصل معك.',
        ],
        'suspended' => [
            'title' => 'تم تعليق :service',
            'body' => 'تم تعليق :service ولم يعد يعمل. سداد الرصيد المستحق يعيده للعمل.',
        ],
        'restored' => [
            'title' => ':service يعمل مجددًا',
            'body' => 'شكرًا لك. تمت إعادة :service وهو متاح الآن.',
        ],
        'reactivation_failed' => [
            'title' => 'تعذّرت إعادة :service حتى الآن',
            'body' => 'تم استلام دفعتك، لكننا لم نتمكن من إعادة :service تلقائيًا. فريقنا يعمل على ذلك وسيعيده قريبًا.',
        ],
        'ended' => [
            'title' => 'انتهى :service',
            'body' => 'توقّف :service في :date لانتهاء الاشتراك. تُحفظ بياناتك حتى :retention_ends ثم تُحذف بعد ذلك.',
        ],
        'data_retention_ending' => [
            'title' => 'ستُحذف بيانات :service في :date',
            'body' => 'لم يُحذف شيء بعد. إن كنت لا تزال بحاجة إلى شيء من :service فخذ نسخة قبل :date — فبعده لا يمكن استرجاعها.',
        ],
        'terminated' => [
            'title' => 'تم إنهاء :service',
            'body' => 'تم إنهاء :service وحذف بياناته. لا يمكن التراجع عن ذلك.',
        ],
        'plan_change_completed' => [
            'title' => ':service أصبح على خطة :plan',
            'body' => 'اكتمل تغيير خطة :service. ستعكس فاتورتك القادمة السعر الجديد.',
        ],
        'plan_change_failed' => [
            'title' => 'تعذّر تغيير خطة :service',
            'body' => 'لم يكتمل تغيير خطة :service وبقي كما كان. لم تُحمَّل أي رسوم مقابل التغيير.',
        ],
        'reinstall_started' => [
            'title' => 'جارٍ إعادة تثبيت :service',
            'body' => 'بدأت إعادة تثبيت :service. سيكون غير متاح أثناء التنفيذ.',
        ],
        'reinstall_completed' => [
            'title' => 'تمت إعادة تثبيت :service',
            'body' => 'تمت إعادة تثبيت :service باستخدام :image وهو يعمل مجددًا.',
        ],
        'reinstall_failed' => [
            'title' => 'لم تكتمل إعادة تثبيت :service',
            'body' => 'لم تكتمل إعادة تثبيت :service. تم تنبيه فريقنا؛ لا تفترض حالة القرص في أي اتجاه حتى نؤكد لك.',
        ],
        'backup_completed' => [
            'title' => 'اكتملت النسخة الاحتياطية لـ :service',
            'body' => 'اكتملت نسخة احتياطية لـ :service بنجاح.',
        ],
        'backup_failed' => [
            'title' => 'فشلت النسخة الاحتياطية لـ :service',
            'body' => 'لم تكتمل نسخة احتياطية لـ :service. نسخك السابقة غير متأثرة.',
        ],
        'restore_completed' => [
            'title' => 'تمت استعادة :service من نسخة احتياطية',
            'body' => 'اكتملت استعادة :service. كل ما كُتب بعد أخذ النسخة لم يعد موجودًا.',
        ],
        'restore_failed' => [
            'title' => 'لم تكتمل استعادة :service',
            'body' => 'لم تكتمل استعادة :service. تم تنبيه فريقنا.',
        ],
        'ticket_opened' => [
            'title' => 'وصلنا طلبك :reference',
            'body' => 'وصلنا طلب الدعم ":subject". سيردّ عليك أحدنا على التذكرة نفسها، ولا حاجة لإرساله مرّة أخرى.',
        ],
        'ticket_replied' => [
            'title' => 'ردّ الدعم على :reference',
            'body' => 'هناك ردّ جديد على طلب الدعم ":subject". افتح التذكرة لقراءته والردّ عليه.',
        ],
        'ticket_resolved' => [
            'title' => 'وُسم :reference بأنّه محلول',
            'body' => 'نعتقد أنّ طلبك ":subject" قد حُلّ. إن لم يكن كذلك، ردّ على التذكرة فتُفتح من جديد بكامل سجلّها.',
        ],
        'ticket_closed' => [
            'title' => 'أُغلقت :reference',
            'body' => 'أُغلق طلب الدعم ":subject". إن احتجت شيئًا آخر، افتح طلبًا جديدًا واذكر هذا الرقم.',
        ],
    ],
    'operational' => [
        'incident' => [
            'title' => 'عطل يؤثر على :service',
            'body' => 'نحن على علم بمشكلة تؤثر على :service ونعمل على حلها. سنخبرك عند المعالجة.',
        ],
        'maintenance_scheduled' => [
            'title' => 'صيانة مجدولة في :date',
            'body' => 'هناك صيانة مجدولة تؤثر على :service في :date. قد تلاحظ انقطاعًا قصيرًا.',
        ],
    ],

    'action' => [
        'open' => 'افتح في بوابتك',
    ],

    'signature' => 'Lynomia Cloud',
];
