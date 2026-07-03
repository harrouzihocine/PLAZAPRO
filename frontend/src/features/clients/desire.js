// The desire form ↔ API payload mapping, shared by every place the profile is
// captured (call log Branch A, shift-to-desire). '' selections become nulls;
// numbers are cast; notes are trimmed (they are required server-side).
export function desirePayload(form) {
  const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v))

  return {
    wilaya_id: form.wilaya_id || null,
    commune_id: form.commune_id || null,
    type_id: form.type_id || null,
    floor_id: form.floor_id || null,
    area_min: num(form.area_min),
    area_max: num(form.area_max),
    rooms_min: num(form.rooms_min),
    budget_min: num(form.budget_min),
    budget_max: num(form.budget_max),
    location_ids: form.location_ids ?? [],
    notes: (form.notes ?? '').trim() || null,
  }
}

// A fresh form, optionally prefilled from a saved desire resource.
export function desireForm(desire = null) {
  return {
    wilaya_id: desire?.wilaya_id ?? '',
    commune_id: desire?.commune_id ?? '',
    type_id: desire?.type_id ?? '',
    floor_id: desire?.floor_id ?? '',
    area_min: desire?.area_min ?? '',
    area_max: desire?.area_max ?? '',
    rooms_min: desire?.rooms_min ?? '',
    budget_min: desire?.budget_min ?? '',
    budget_max: desire?.budget_max ?? '',
    location_ids: desire?.location_ids ?? [],
    notes: desire?.notes ?? '',
  }
}
