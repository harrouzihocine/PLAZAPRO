// Person-name helpers. Keep this logic in sync with the backend
// Client::normalizeName() and Client full_name accessor.

/**
 * Standardize a person name: collapse inner whitespace and title-case each word,
 * preserving hyphens and apostrophes ("jean-paul o'brien" => "Jean-Paul O'Brien").
 * A single trailing space is preserved so users can keep typing the next word.
 */
export function titleCaseName(value) {
  if (value == null) return ''
  const trailingSpace = /\s$/.test(value) ? ' ' : ''
  const collapsed = String(value).replace(/\s+/g, ' ').trimStart()
  const cased = collapsed.replace(
    /\p{L}[\p{L}\p{M}]*/gu,
    (word) => word.charAt(0).toLocaleUpperCase() + word.slice(1).toLocaleLowerCase(),
  )
  return cased + trailingSpace
}

/** Display name, last name first: "Dupont Jean". */
export function fullName(person) {
  if (!person) return ''
  return [person.last_name, person.first_name]
    .map((part) => titleCaseName(part).trim())
    .filter(Boolean)
    .join(' ')
}
