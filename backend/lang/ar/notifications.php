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
    'office_visit_scheduled' => [
        'title' => 'زيارة مكتب قادمة',
        'body' => 'زيارة :type مع :client:extra — مع :agent.',
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
    'chat_group' => 'محادثة جماعية',
    'chat_new_message' => 'رسالة جديدة',
    'chat_attachment' => 'أرسل مرفقًا',
    'message_deleted' => 'رسالة محذوفة',
    'message_photo' => '📷 صورة',
    'message_voice' => '🎤 رسالة صوتية',
    'message_file' => '📎 ملف',
];
