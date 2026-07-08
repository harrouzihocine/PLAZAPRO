<?php

declare(strict_types=1);

namespace App\Modules\Settings\Support;

/**
 * en/fr/ar labels for the DEFAULT dynamic-list items (the ones DynamicListSeeder
 * ships). Keyed by list key → item value; applied by the seeder on fresh
 * installs and by the label_translations migration as a backfill on live DBs.
 * Items an admin added in-app aren't here — they translate via the Lists admin
 * (Settings → Lists → item → translations), falling back to their base label.
 * Brand names (Facebook, Ouedkniss…) and codes (F2, F3…) stay untranslated on
 * purpose. Display rule everywhere: label_translations[locale] ?? label.
 */
class DefaultListTranslations
{
    /** @return array<string, array<string, array{en?: string, fr?: string, ar?: string}>> */
    public static function map(): array
    {
        return [
            'project_payment_methods' => [
                'alkrd_albnky_ghyr_mtofr' => ['en' => 'Bank loan not available', 'fr' => 'Crédit bancaire non disponible', 'ar' => 'القرض البنكي غير متوفر'],
                'alkrd_albnky_mtofr' => ['en' => 'Bank loan available', 'fr' => 'Crédit bancaire disponible', 'ar' => 'القرض البنكي متوفر'],
                'amkany_altksyt' => ['en' => 'Instalments possible', 'fr' => 'Paiement échelonné possible', 'ar' => 'إمكانية التقسيط'],
                'aldfaa_kash' => ['en' => 'Cash payment', 'fr' => 'Paiement cash', 'ar' => 'الدفع كاش'],
                'bank_transfer' => ['en' => 'Bank transfer', 'fr' => 'Virement bancaire', 'ar' => 'التحويل البنكي'],
                'transfer' => ['en' => 'Transfer', 'fr' => 'Transfert', 'ar' => 'تحويل'],
            ],
            'payment_methods' => [
                'cash' => ['en' => 'Cash', 'fr' => 'Espèces', 'ar' => 'نقدًا'],
                'bank_transfer' => ['en' => 'Bank transfer', 'fr' => 'Virement bancaire', 'ar' => 'تحويل بنكي'],
                'cheque' => ['en' => 'Cheque', 'fr' => 'Chèque', 'ar' => 'شيك'],
            ],
            'sources' => [
                'walk_in' => ['en' => 'Walk-in', 'fr' => 'Visite spontanée', 'ar' => 'زيارة مباشرة'],
                'referral' => ['en' => 'Referral', 'fr' => 'Recommandation', 'ar' => 'توصية'],
                'phone_call' => ['en' => 'Phone call', 'fr' => 'Appel téléphonique', 'ar' => 'مكالمة هاتفية'],
            ],
            'client_ratings' => [
                'hot' => ['en' => 'Hot', 'fr' => 'Chaud', 'ar' => 'ساخن'],
                'warm' => ['en' => 'Warm', 'fr' => 'Tiède', 'ar' => 'دافئ'],
                'cold' => ['en' => 'Cold', 'fr' => 'Froid', 'ar' => 'بارد'],
            ],
            'cancellation_reasons' => [
                'changed_mind' => ['en' => 'Changed mind', 'fr' => "A changé d'avis", 'ar' => 'غيّر رأيه'],
                'financing_failed' => ['en' => 'Financing fell through', 'fr' => 'Financement échoué', 'ar' => 'تعذّر التمويل'],
                'found_alternative' => ['en' => 'Found an alternative', 'fr' => 'A trouvé une alternative', 'ar' => 'وجد بديلًا'],
                'price_too_high' => ['en' => 'Price too high', 'fr' => 'Prix trop élevé', 'ar' => 'السعر مرتفع جدًا'],
                'other' => ['en' => 'Other', 'fr' => 'Autre', 'ar' => 'أخرى'],
            ],
            'project_types' => [
                'akam_mftoh' => ['en' => 'Open residence', 'fr' => 'Résidence ouverte', 'ar' => 'إقامة مفتوحة'],
                'akam_mghlk' => ['en' => 'Closed residence', 'fr' => 'Résidence fermée', 'ar' => 'إقامة مغلقة'],
                'akam_shbh_mghlk' => ['en' => 'Semi-closed residence', 'fr' => 'Résidence semi-fermée', 'ar' => 'إقامة شبه مغلقة'],
            ],
            'room_numbers' => [
                'studio' => ['en' => 'Studio', 'fr' => 'Studio', 'ar' => 'ستوديو'],
                'dublex' => ['en' => 'Duplex', 'fr' => 'Duplex', 'ar' => 'دوبلكس'],
            ],
            'contract_types' => [
                'hs_fy_alard' => ['en' => 'Land share', 'fr' => 'Quote-part du terrain', 'ar' => 'حصة في الأرض'],
                'dftr_aakary' => ['en' => 'Land registry title', 'fr' => 'Livret foncier', 'ar' => 'دفتر عقاري'],
                'oaad_balbyaa' => ['en' => 'Promise of sale', 'fr' => 'Promesse de vente', 'ar' => 'وعد بالبيع'],
                'byaa_aal_altsmym' => ['en' => 'Off-plan sale (VEFA)', 'fr' => 'Vente sur plan (VEFA)', 'ar' => 'بيع على التصميم'],
            ],
            'call_topics' => [
                'introduced_project' => ['en' => 'Introduced the project', 'fr' => 'Présentation du projet', 'ar' => 'تعريف بالمشروع'],
                'discussed_budget' => ['en' => 'Discussed budget', 'fr' => 'Budget discuté', 'ar' => 'مناقشة الميزانية'],
                'sent_media' => ['en' => 'Sent brochure / media', 'fr' => 'Brochure / médias envoyés', 'ar' => 'إرسال الوثائق'],
                'interested' => ['en' => 'Interested', 'fr' => 'Intéressé', 'ar' => 'مهتم'],
                'not_interested' => ['en' => 'Not interested (now)', 'fr' => "Pas intéressé (pour l'instant)", 'ar' => 'غير مهتم (حاليًا)'],
                'requested_callback' => ['en' => 'Requested a callback', 'fr' => 'Rappel demandé', 'ar' => 'طلب معاودة الاتصال'],
                'requested_office_visit' => ['en' => 'Requested an office visit', 'fr' => 'Visite bureau demandée', 'ar' => 'طلب زيارة المكتب'],
                'requested_an_office_visit' => ['en' => 'Requested an in-site visit', 'fr' => 'Visite sur site demandée', 'ar' => 'طلب زيارة ميدانية'],
                'price_negotiation' => ['en' => 'Price negotiation', 'fr' => 'Négociation du prix', 'ar' => 'تفاوض على السعر'],
                'no_answer' => ['en' => 'No answer', 'fr' => 'Pas de réponse', 'ar' => 'لا يوجد رد'],
                'wrong_number' => ['en' => 'Wrong number', 'fr' => 'Faux numéro', 'ar' => 'رقم خاطئ'],
            ],
            'objection_reasons' => [
                'price_too_high' => ['en' => 'Price too high', 'fr' => 'Prix trop élevé', 'ar' => 'السعر مرتفع جدًا'],
                'location_not_preferred' => ['en' => 'Location not preferred', 'fr' => 'Emplacement non souhaité', 'ar' => 'الموقع غير مفضل'],
                'payment_plan_too_short' => ['en' => 'Payment plan too short', 'fr' => 'Échéancier trop court', 'ar' => 'مدة التقسيط قصيرة جدًا'],
                'delivery_too_far' => ['en' => 'Delivery date too far', 'fr' => 'Livraison trop lointaine', 'ar' => 'موعد التسليم بعيد جدًا'],
                'unit_too_small' => ['en' => 'Unit too small', 'fr' => 'Logement trop petit', 'ar' => 'الوحدة صغيرة جدًا'],
                'unit_too_large' => ['en' => 'Unit too large', 'fr' => 'Logement trop grand', 'ar' => 'الوحدة كبيرة جدًا'],
                'floor_not_preferred' => ['en' => 'Floor not preferred', 'fr' => 'Étage non souhaité', 'ar' => 'الطابق غير مفضل'],
                'financing_difficulty' => ['en' => 'Financing difficulty', 'fr' => 'Difficulté de financement', 'ar' => 'صعوبة في التمويل'],
                'prefers_another_project' => ['en' => 'Prefers another project', 'fr' => 'Préfère un autre projet', 'ar' => 'يفضل مشروعًا آخر'],
                'just_comparing' => ['en' => 'Just comparing', 'fr' => 'Compare seulement', 'ar' => 'يقارن فقط'],
                'wants_more_discount' => ['en' => 'Wants more discount', 'fr' => 'Veut plus de remise', 'ar' => 'يريد تخفيضًا أكبر'],
            ],
            'office_visit_checklist' => [
                'showed_stacking_plan' => ['en' => 'Showed stacking plan', 'fr' => "Plan d'étages présenté", 'ar' => 'عرض مخطط البناية'],
                'presented_units' => ['en' => 'Presented units', 'fr' => 'Unités présentées', 'ar' => 'عرض الوحدات'],
                'discussed_price' => ['en' => 'Discussed price', 'fr' => 'Prix discuté', 'ar' => 'مناقشة السعر'],
                'discussed_payment_plan' => ['en' => 'Discussed payment plan', 'fr' => 'Échéancier discuté', 'ar' => 'مناقشة خطة الدفع'],
                'showed_media' => ['en' => 'Showed media', 'fr' => 'Médias présentés', 'ar' => 'عرض الصور والوثائق'],
                'client_satisfied' => ['en' => 'Client satisfied', 'fr' => 'Client satisfait', 'ar' => 'العميل راضٍ'],
                'requested_insite_visit' => ['en' => 'Requested an in-site visit', 'fr' => 'Visite sur site demandée', 'ar' => 'طلب زيارة ميدانية'],
            ],
            'insite_outcomes' => [
                'not_visited' => ['en' => 'Not visited', 'fr' => 'Non visité', 'ar' => 'لم تُزَر'],
                'visited_interested' => ['en' => 'Visited — interested', 'fr' => 'Visité — intéressé', 'ar' => 'زار — مهتم'],
                'visited_not_interested' => ['en' => 'Visited — not interested', 'fr' => 'Visité — pas intéressé', 'ar' => 'زار — غير مهتم'],
                'needs_second_visit' => ['en' => 'Needs a second visit', 'fr' => 'Deuxième visite nécessaire', 'ar' => 'يحتاج زيارة ثانية'],
            ],
            'next_action_change_reasons' => [
                'client_reschedule' => ['en' => 'Client requested a reschedule', 'fr' => 'Report demandé par le client', 'ar' => 'طلب العميل تأجيل الموعد'],
                'client_unavailable' => ['en' => 'Client unavailable', 'fr' => 'Client indisponible', 'ar' => 'العميل غير متاح'],
                'changed_type' => ['en' => 'Changed the type of next step', 'fr' => "Type d'étape modifié", 'ar' => 'تغيير نوع الخطوة التالية'],
                'reassigned' => ['en' => 'Reassigned to another agent', 'fr' => 'Réassigné à un autre agent', 'ar' => 'أُسند إلى وكيل آخر'],
                'data_entry_error' => ['en' => 'Logged by mistake', 'fr' => 'Saisi par erreur', 'ar' => 'سُجّل عن طريق الخطأ'],
                'other' => ['en' => 'Other', 'fr' => 'Autre', 'ar' => 'أخرى'],
            ],
            'archive_reasons' => [
                'changed_mind' => ['en' => 'Changed mind', 'fr' => "A changé d'avis", 'ar' => 'غيّر رأيه'],
                'found_alternative' => ['en' => 'Found an alternative', 'fr' => 'A trouvé une alternative', 'ar' => 'وجد بديلًا'],
                'price_too_high' => ['en' => 'Price too high', 'fr' => 'Prix trop élevé', 'ar' => 'السعر مرتفع جدًا'],
                'financing_failed' => ['en' => 'Financing fell through', 'fr' => 'Financement échoué', 'ar' => 'تعذّر التمويل'],
                'postponed' => ['en' => 'Postponed', 'fr' => 'Reporté', 'ar' => 'مؤجّل'],
                'other' => ['en' => 'Other', 'fr' => 'Autre', 'ar' => 'أخرى'],
            ],
            'box_types' => [
                'parking' => ['en' => 'Parking', 'fr' => 'Parking', 'ar' => 'موقف سيارات'],
                'storage' => ['en' => 'Storage', 'fr' => 'Cellier', 'ar' => 'مخزن'],
            ],
            'floors' => [
                'ground' => ['en' => 'Ground floor', 'fr' => 'Rez-de-chaussée', 'ar' => 'الطابق الأرضي'],
                'floor_1' => ['en' => '1st floor', 'fr' => '1er étage', 'ar' => 'الطابق الأول'],
                'floor_2' => ['en' => '2nd floor', 'fr' => '2e étage', 'ar' => 'الطابق الثاني'],
                'floor_3' => ['en' => '3rd floor', 'fr' => '3e étage', 'ar' => 'الطابق الثالث'],
                'floor_4' => ['en' => '4th floor', 'fr' => '4e étage', 'ar' => 'الطابق الرابع'],
                'floor_5' => ['en' => '5th floor', 'fr' => '5e étage', 'ar' => 'الطابق الخامس'],
                '6th_floor' => ['en' => '6th floor', 'fr' => '6e étage', 'ar' => 'الطابق السادس'],
                '7th_floor' => ['en' => '7th floor', 'fr' => '7e étage', 'ar' => 'الطابق السابع'],
                '8th_floor' => ['en' => '8th floor', 'fr' => '8e étage', 'ar' => 'الطابق الثامن'],
                '9th_floor' => ['en' => '9th floor', 'fr' => '9e étage', 'ar' => 'الطابق التاسع'],
            ],
            'visit_outcomes' => [
                'interested' => ['en' => 'Interested', 'fr' => 'Intéressé', 'ar' => 'مهتم'],
                'not_interested' => ['en' => 'Not interested', 'fr' => 'Pas intéressé', 'ar' => 'غير مهتم'],
                'needs_followup' => ['en' => 'Needs follow-up', 'fr' => 'Suivi nécessaire', 'ar' => 'يحتاج متابعة'],
                'reserved' => ['en' => 'Reserved', 'fr' => 'Réservé', 'ar' => 'محجوز'],
                'no_show' => ['en' => 'No-show', 'fr' => 'Absent', 'ar' => 'لم يحضر'],
            ],
            'call_outcomes' => [
                'answered' => ['en' => 'Answered', 'fr' => 'A répondu', 'ar' => 'تم الرد'],
                'no_answer' => ['en' => 'No answer', 'fr' => 'Pas de réponse', 'ar' => 'لا يوجد رد'],
                'callback_requested' => ['en' => 'Callback requested', 'fr' => 'Rappel demandé', 'ar' => 'طلب معاودة الاتصال'],
                'interested' => ['en' => 'Interested', 'fr' => 'Intéressé', 'ar' => 'مهتم'],
                'not_interested' => ['en' => 'Not interested', 'fr' => 'Pas intéressé', 'ar' => 'غير مهتم'],
                'wrong_number' => ['en' => 'Wrong number', 'fr' => 'Faux numéro', 'ar' => 'رقم خاطئ'],
            ],
        ];
    }
}
