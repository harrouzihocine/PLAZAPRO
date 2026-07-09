// Compact auto-generated unit reference — the live preview the unit forms
// write into the reference field as the user fills the specs:
// `{LOC}-{ROOMS}-{BLOCK}{FLOOR}-{POS}` with empty parts skipped
// (e.g. "AQU-F3-A2-05", "KAI-F2-B", "NOU-STUDIO-RDC"), plus a numeric suffix
// on collision. Backend twin (used when a CSV import row has no reference):
// app/Modules/Inventory/Support/UnitReference.php — keep the two in sync.
// Always built from BASE labels (item.label), never localized ones, so the
// same specs yield the same reference in en/fr/ar.

function compact(value) {
  const clean = String(value ?? '')
    .replace(/\s+/g, '')
    .toUpperCase()
  return clean || null
}

// First 3 alphanumeric characters of the project code (name as fallback).
function locationCode(location) {
  for (const source of [location?.code, location?.name]) {
    const clean = String(source ?? '')
      .replace(/[^A-Za-z0-9]/g, '')
      .toUpperCase()
    if (clean) return clean.slice(0, 3)
  }
  return null
}

// Floor label → shortest code: "3rd Floor"→"3", "Ground Floor"→"RDC",
// "Sous-sol 1"→"SS1"; a bare stack_floor number is the fallback.
function floorCode(label, stackFloor) {
  const text = String(label ?? '').trim()
  if (text) {
    const digits = text.match(/(\d+)/)
    if (/sous|basement/i.test(text)) return digits ? `SS${digits[1]}` : 'SS'
    if (/ground|rdc|rez/i.test(text)) return 'RDC'
    if (digits) return digits[1]
    return compact(text.slice(0, 3))
  }
  return stackFloor === '' || stackFloor === null || stackFloor === undefined
    ? null
    : String(stackFloor)
}

export function buildUnitRef(location, { roomsLabel, floorLabel, block, stackFloor, position } = {}) {
  const pos =
    position === '' || position === null || position === undefined
      ? null
      : String(position).padStart(2, '0')

  const parts = [
    locationCode(location),
    compact(roomsLabel),
    (compact(block) ?? '') + (floorCode(floorLabel, stackFloor) ?? ''),
    pos,
  ].filter((p) => p)

  // Never emit an empty reference (a unit with no specs yet).
  return parts.length ? parts.join('-') : 'UNIT'
}

// First available reference: the base itself, else base-2, base-3, …
// (case-insensitive against the refs already taken in the project).
export function dedupeRef(base, taken = []) {
  const set = new Set(taken.map((r) => String(r ?? '').trim().toLowerCase()))
  if (!set.has(base.toLowerCase())) return base
  for (let n = 2; ; n++) {
    const candidate = `${base}-${n}`
    if (!set.has(candidate.toLowerCase())) return candidate
  }
}
