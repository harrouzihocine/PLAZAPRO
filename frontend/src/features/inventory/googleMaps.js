// Build a Google Maps deep link for a project. Prefer the exact point picked on
// the map; otherwise search the typed address. Returns null when we have neither
// so callers can hide the link.
export function googleMapsUrl({ latitude, longitude, address } = {}) {
  const hasCoords = latitude != null && latitude !== '' && longitude != null && longitude !== ''
  if (hasCoords) {
    return `https://www.google.com/maps/search/?api=1&query=${latitude},${longitude}`
  }
  const trimmed = address?.trim()
  if (trimmed) {
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(trimmed)}`
  }
  return null
}
