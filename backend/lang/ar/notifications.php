<?php

declare(strict_types=1);

// عناوين/نصوص الإشعارات، تُحل حسب المستلم — مرآة لملف en/notifications.php.
return [
    'type' => [
        'office' => 'مكتبية',
        'in_site' => 'ميدانية',
    ],
    'group' => [
        'calls' => 'مكالمة/مكالمات',
        'office_visits' => 'زيارة/زيارات مكتب',
        'in_site_visits' => 'زيارة/زيارات ميدانية',
        'tasks' => 'مهمة/مهام',
    ],

    'empty_client' => [
        'title' => 'عميل فارغ — تابعه',
        'body' => 'سجّلت :name لكن لم يُسجل أي شيء بعد.',
    ],
    'client_assigned' => [
        'title' => 'أُسند إليك عميل منتظر',
        'body' => 'أعد التواصل مع :name — رغباته تطابق الآن المخزون المتاح.',
    ],
    'project_set_up' => [
        'title' => 'جُهّز لك عميل',
        'body' => 'أعدّ مشرف هذا العميل كمشروع خاص بك — افتحه للبدء.',
    ],
    'project_shared' => [
        'title' => 'شورك معك مشروع',
        'body' => 'شارك معك مشرف مشروع عميل موجود.',
    ],
    'project_handed' => [
        'title' => 'سُلّم إليك مشروع',
        'body' => 'أعاد مشرف تفعيل مشروع عميل ووضعك عليه — افتحه للمتابعة.',
    ],
    'duplicate_denied' => [
        'title' => 'رُفض طلب التكرار',
        'body' => 'رُفض طلبك لإضافة عميل موجود.',
    ],
    'duplicate_attempt' => [
        'title' => 'محاولة عميل مكرر',
        'body' => 'حاول :name إضافة عميل موجود مسبقًا.',
    ],
    'account_locked' => [
        'title' => 'قُفل الحساب: :name',
        'body' => ':attempts محاولات دخول فاشلة. فُك قفله من صفحة المستخدمين.',
    ],
    'dispatch_request' => [
        'title' => 'زيارة ميدانية تحتاج وكيلًا',
        'body' => 'زيارة لـ :client مستحقة :date — عيّن وكيل ميدان من اللوحة.',
    ],
    'work_transferred' => [
        'title' => 'سُلّم إليك العمل المفتوح لـ :from',
        'body' => 'استلمت :count عنصرًا مفتوحًا. قائمة "القادم" في لوحتك فيها التفاصيل.',
    ],
    'plans_pooled' => [
        'title' => 'عادت خطط ميدانية إلى الطابور',
        'body' => 'عادت :count خطة ميدانية من :from إلى الطابور — عيّن وكلاء جددًا من اللوحة.',
    ],
    'queue_first' => [
        'title' => 'عميلك الآن أول القائمة',
        'body' => 'حُرر الحجز على :unit — عميلك التالي. اتصل به قبل أن تذهب الوحدة.',
    ],
    'queue_cancelled' => [
        'title' => 'أُلغي الحجز — بيعت الوحدة',
        'body' => 'بيعت :unit لعميل آخر. أُلغي حجز عميلك (كان رقم :position في القائمة).',
    ],
    'upcoming_digest' => [
        'title' => 'لديك :count :group قادمة',
        'body' => ':lines',
    ],
    'upcoming_digest_overdue' => [
        'title' => 'لديك :count :group قادمة (:overdue متأخرة)',
        'body' => ':lines',
    ],
    'digest_more' => '… و:count أخرى',
    'visit_assigned' => [
        'title' => 'أُسندت إليك زيارة',
        'body' => 'زيارة :type مع :client:extra.',
    ],
    'visit_agent_assigned' => [
        'title' => 'عُيّن وكيل الميدان',
        'body' => 'سيتولى :agent الزيارة الـ:type مع :client:extra.',
    ],
    'visit_completed' => [
        'title' => 'اكتمل سجل الزيارة',
        'body' => 'أكمل :agent سجل الزيارة الـ:type مع :client:extra.',
    ],
    'office_visit_scheduled' => [
        'title' => 'زيارة مكتب قادمة',
        'body' => 'زيارة :type مع :client:extra — مع :agent.',
    ],
    'office_visit_approval' => [
        'title' => 'زيارة مكتب تحتاج موافقتك',
        'body' => ':user يريد زيارة مكتب مع :client بتاريخ :date — خارج نافذة :days يوم/أيام. وافق أو ارفض أو أعد جدولتها من صفحة البرنامج.',
    ],
    'office_visit_approved' => [
        'title' => 'تمت الموافقة على زيارة المكتب',
        'body' => 'وافق :manager على زيارة المكتب مع :client بتاريخ :date.',
    ],
    'office_visit_denied' => [
        'title' => 'رُفضت زيارة المكتب — خطّط موعدًا جديدًا',
        'body' => 'رفض :manager زيارة المكتب مع :client بتاريخ :date (:reason). خطّط موعدًا أقرب مع العميل.',
    ],
    'office_visit_rescheduled' => [
        'title' => 'أُعيدت جدولة زيارة المكتب',
        'body' => 'نقل :manager زيارة المكتب مع :client إلى :date.',
    ],
    'unit_match_new' => [
        'title' => 'وحدة جديدة تطابق عميلًا',
        'body' => 'الوحدة :unit:details تناسب: :clients.',
    ],
    'unit_match_repriced' => [
        'title' => 'تغير سعر وحدة مطابقة',
        'body' => 'الوحدة :unit:details تناسب: :clients.',
    ],
    'call_prompt' => [
        'title' => 'اتصلت بـ :name',
        'body' => 'أتريد تسجيل هذه المكالمة؟ اضغط لفتح سجل المكالمات.',
    ],
    'reminder' => [
        'title' => 'متابعة مستحقة',
        'body' => 'إجراؤك التالي (:type) مستحق الآن.',
    ],
    'reminder_type' => [
        'call' => 'مكالمة',
        'office_visit' => 'زيارة مكتب',
        'in_site_visit' => 'زيارة ميدانية',
    ],
    'task_reminder' => [
        'title' => 'مهمة مستحقة',
        'body' => 'مهمتك ":title" مستحقة الآن.',
    ],
    'task_assigned' => [
        'title' => 'مهمة جديدة لك',
        'body' => ':name أسند إليك مهمة: ":title".',
    ],
    'task_completed' => [
        'title' => 'أُنجزت مهمة — راجعها',
        'body' => ':name أنهى ":title" (:outcome). افتح اللوحة لقراءة التقرير.',
    ],
    'task_outcome' => [
        'full' => 'أُنجزت كاملة',
        'partial' => 'أُنجزت جزئيًا',
        'issues' => 'أُنجزت مع صعوبات',
    ],
    'payment' => [
        'title' => 'سُجلت دفعة',
        'body' => 'سُجلت دفعة بمبلغ :amount على صفقة :client.',
    ],
    'reserved_lapsed' => [
        'title' => 'انتهى الحجز',
        'body' => 'انقضى الحجز على :unit — عادت إلى السوق.',
    ],
    'unit_published' => [
        'title' => 'أُضيفت وحدة جديدة',
        'body' => ':details',
    ],
    'box_published' => [
        'title' => 'أُضيف صندوق جديد',
        'body' => ':details',
    ],
    'unit_updated' => [
        'title' => 'حُدّثت الوحدة :unit',
        'body' => ':details',
    ],
    'units_imported' => [
        'title' => 'تم استيراد المخزون',
        'body' => 'قام :user باستيراد وحدات من ملف — :created أُضيفت، :updated حُدِّثت.',
    ],
    'box_updated' => [
        'title' => 'حُدّث الصندوق :box',
        'body' => ':details',
    ],
    'unit_sold' => [
        'title' => 'بيعت وحدة 🎉',
        'body' => ':details',
    ],
    'unit_status' => [
        'title' => 'الوحدة :unit — :status',
        'body' => ':status:details',
    ],
    'unit_status_label' => [
        'interested' => 'مهتم بها',
        'reserved' => 'محجوزة',
        'available' => 'عادت إلى السوق',
    ],
    'chat_message' => [
        'title' => ':title',
        'body' => ':preview',
    ],
    // First message a sender has ever written to this recipient — the only
    // chat traffic that lands in the bell feed.
    'chat_first_message' => [
        'title' => 'محادثة جديدة من :name',
        'body' => ':preview',
    ],
    'chat_group' => 'محادثة جماعية',
    'chat_new_message' => 'رسالة جديدة',
    'chat_attachment' => 'أرسل مرفقًا',
    'message_deleted' => 'رسالة محذوفة',
    'message_photo' => '📷 صورة',
    'message_voice' => '🎤 رسالة صوتية',
    'message_file' => '📎 ملف',

    // طبقة GPS للإرسال: رفض مهمة، مهمة لم تُقبل ضمن المهلة، تأخر الوصول للموقع.
    'visit_declined' => [
        'title' => 'تم رفض الزيارة',
        'body' => 'رفض :agent الزيارة مع :client:extra — «:reason».',
    ],
    'visit_unaccepted' => [
        'title' => 'مهمة لم تُقبل بعد',
        'body' => 'لم يقبل :agent بعد الزيارة مع :client (:when).',
    ],
    'visit_late' => [
        'title' => 'الوكيل متأخر',
        'body' => 'لم يصل :agent بعد إلى موقع الزيارة مع :client (:when).',
    ],
    'visit_offroute' => [
        'title' => 'الوكيل خارج المسار',
        'body' => 'انحرف :agent عن الطريق نحو :site (زيارة مع :client).',
    ],
    'agent_idle' => [
        'title' => 'الوكيل متوقف',
        'body' => ':agent متوقف منذ :minutes دقيقة وهو في وضع متاح.',
    ],


    'locate_request' => [
        'title' => 'تحديث الموقع',
        'body' => 'قام المرسل بتحديث موقعك المباشر.',
    ],

    'duty_reminder' => [
        'title' => 'صباح الخير — فعّل الخدمة',
        'body' => 'افتح «يومي» وفعّل زر الخدمة حتى يتمكن المرسل من الوصول إليك.',
    ],
    'duty_nudge' => [
        'title' => 'المرسل يطلبك في الخدمة',
        'body' => 'يطلب منك :dispatcher تفعيل الخدمة (والموقع) في «يومي».',
    ],
    'duty_gps_lost_agent' => [
        'title' => 'أُوقفت الخدمة — الموقع مغلق',
        'body' => 'تم إيقاف الموقع في هاتفك فانتهت الخدمة. أعد تشغيل الموقع ثم فعّل الخدمة من «يومي».',
    ],
    'duty_gps_lost_dispatcher' => [
        'title' => 'انقطع تتبع الوكيل',
        'body' => 'تم إيقاف الموقع في هاتف :agent — فأُنهيت خدمته تلقائيًا.',
    ],
];
