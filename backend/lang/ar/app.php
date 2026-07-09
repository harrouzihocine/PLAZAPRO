<?php

declare(strict_types=1);

return [
    'duplicate_own' => 'يوجد عميل بهذا الهاتف في قائمتك بالفعل.',
    'duplicate_other' => 'هذا الهاتف يخص عميل مستخدم آخر. أُرسل طلب إلى مشرف.',
    // استيراد CSV للوحدات (أخطاء كل سطر تُعاد إلى المستورد).
    'units_import_bad_header' => 'ملف غير معروف: يجب أن يحتوي السطر الأول على أسماء الأعمدة (استخدم ملف CSV مُصدَّرًا كنموذج).',
    'units_import_unknown_id' => 'لا توجد وحدة نشطة بالمعرّف :id.',
    'units_import_unknown_project' => 'المشروع «:project» غير معروف.',
    'units_import_project_required' => 'الوحدة الجديدة تحتاج إلى مشروع (عمود location_id أو project).',
    'units_import_price_required' => 'الوحدة الجديدة تحتاج إلى سعر.',
    'unit_needs_one_price' => 'يجب أن تحتفظ الوحدة بسعر واحد على الأقل (نصف مشطبة أو مشطبة).',
    'unit_correction_empty' => 'لا يوجد ما يُصحَّح — أرسل سعرًا أو حالة.',
    'units_import_unknown_item' => 'القيمة «:value» غير معروفة.',
    'units_import_duplicate_reference' => 'المرجع «:reference» موجود بالفعل في هذا المشروع.',
    'units_import_reason' => 'استيراد CSV بواسطة :user',
];
