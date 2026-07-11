// The desire form ↔ API payload mapping, shared by every place the profile is
// captured (call log Branch A, shift-to-desire). Every selector is
// multi-valued (id arrays; empty array = no preference); numbers are cast;
// notes are trimmed (they are required server-side).
export function desirePayload(form) {
  const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v))

  return {
    wilaya_ids: form.wilaya_ids ?? [],
    commune_ids: form.commune_ids ?? [],
    type_ids: form.type_ids ?? [],
    room_number_ids: form.room_number_ids ?? [],
    contract_type_ids: form.contract_type_ids ?? [],
    floor_ids: form.floor_ids ?? [],
    area_min: num(form.area_min),
    area_max: num(form.area_max),
    budget_min: num(form.budget_min),
    budget_max: num(form.budget_max),
    location_ids: form.location_ids ?? [],
    notes: (form.notes ?? '').trim() || null,
  }
}

// A fresh form, optionally prefilled from a saved desire resource.
export function desireForm(desire = null) {
  return {
    wilaya_ids: desire?.wilaya_ids ?? [],
    commune_ids: desire?.commune_ids ?? [],
    type_ids: desire?.type_ids ?? [],
    room_number_ids: desire?.room_number_ids ?? [],
    contract_type_ids: desire?.contract_type_ids ?? [],
    floor_ids: desire?.floor_ids ?? [],
    area_min: desire?.area_min ?? '',
    area_max: desire?.area_max ?? '',
    budget_min: desire?.budget_min ?? '',
    budget_max: desire?.budget_max ?? '',
    location_ids: desire?.location_ids ?? [],
    notes: desire?.notes ?? '',
  }
}
