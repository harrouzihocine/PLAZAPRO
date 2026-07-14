<?php

declare(strict_types=1);

return [
    'duplicate_own' => 'يوجد عميل بهذا الهاتف في قائمتك بالفعل.',
    'duplicate_other' => 'هذا الهاتف يخص عميل مستخدم آخر. أُرسل طلب إلى مشرف.',
    // استيراد Excel للوحدات (أخطاء كل سطر تُعاد إلى المستورد).
    'units_import_bad_header' => 'ملف غير معروف: يجب أن يحتوي السطر الأول على أسماء الأعمدة (نزّل نموذج الاستيراد أو ابدأ من ملف مُصدَّر).',
    'units_import_unknown_id' => 'لا توجد وحدة نشطة بالمعرّف :id.',
    'units_import_unknown_project' => 'المشروع «:project» غير معروف.',
    'units_import_project_required' => 'الوحدة الجديدة تحتاج إلى مشروع (عمود location_id أو project).',
    'units_import_price_required' => 'الوحدة الجديدة تحتاج إلى سعر.',
    'unit_needs_one_price' => 'يجب أن تحتفظ الوحدة بسعر واحد على الأقل (نصف جاهزة أو جاهزة).',
    'unit_correction_empty' => 'لا يوجد ما يُصحَّح — أرسل سعرًا أو حالة.',
    'units_import_unknown_item' => 'القيمة «:value» غير معروفة.',
    'units_import_duplicate_reference' => 'المرجع «:reference» موجود بالفعل في هذا المشروع.',
    'units_import_reason' => 'استيراد Excel بواسطة :user',
    'units_import_archived_reason' => 'غير موجودة في آخر استيراد',
    // تصدير الوحدات بصيغة xlsx. / نموذج الاستيراد (أسماء الأوراق + ورقة الدليل).
    'units_sheet_units' => 'الوحدات',
    'units_sheet_guide' => 'الدليل',
    'units_guide_note' => 'كل سطر يمثّل وحدة واحدة. استبدل أسطر الأمثلة الرمادية ببياناتك ثم استورد الملف من صفحة الوحدات. تُولَّد المراجع تلقائيًا. الأسطر التي تحمل عمود «id» (من ملف مُصدَّر) تُحدِّث الوحدة بدل إنشاء واحدة جديدة؛ ولا يغيّر الاستيراد حالة البيع أبدًا.',
    'units_guide_column' => 'العمود',
    'units_guide_required' => 'إلزامي',
    'units_guide_description' => 'الوصف',
    'units_guide_allowed' => 'القيم المسموح بها',
    'units_guide_yes' => 'نعم',
    'units_guide_no' => 'لا',
    'units_guide_one_price' => 'أحد السعرين على الأقل',
    'units_guide_number' => 'رقم',
    'units_guide_auto' => 'نص حر — اتركه فارغًا ليُولَّد تلقائيًا',
    'units_guide_project' => 'المشروع الذي تتبع له الوحدة — يجب أن يطابق اسم مشروع موجود تمامًا.',
    'units_guide_reference' => 'مرجع الوحدة، فريد داخل مشروعه.',
    'units_guide_rooms' => 'نوع الوحدة (F2، F3…) كما هو مضبوط في الإعدادات.',
    'units_guide_floor' => 'الطابق كما هو مضبوط في الإعدادات.',
    'units_guide_area_sqm' => 'المساحة السكنية بالمتر المربع.',
    'units_guide_price_semi_fini' => 'سعر نصف جاهزة، بالدينار الجزائري.',
    'units_guide_price_fini' => 'سعر جاهزة، بالدينار الجزائري.',
    'units_guide_gtm_priority' => 'أولوية البيع (فارغ = medium).',
    'units_guide_block' => 'العمارة / المدخل.',
    'units_guide_stack_floor' => 'رقم الطابق المستخدم في مخطط التوزيع.',
    'units_guide_position' => 'الموضع في الطابق (مخطط التوزيع).',
    'units_guide_payment_methods' => 'طرق الدفع لهذه الوحدة. اتركه فارغًا لوراثة طرق المشروع؛ أو اذكر الطرق (مفصولة بفواصل) لتخصيص هذه الوحدة (مثال: نقدًا فقط).',
    'units_guide_unit_note' => 'ملاحظة نصية حرة تظهر أينما ظهرت الوحدة.',
    'units_example_project' => 'مشروع مثال — استبدله',
    'units_example_note' => 'وحدة زاوية، تخزين إضافي',

    // مشاركة الوسائط (إرسال واتساب للعميل)
    'media_share_bad_items' => 'بعض العناصر المحددة لم يعد بالإمكان مشاركتها (أُزيلت، أو ليست صورًا/فيديوهات/مخططات).',
];
