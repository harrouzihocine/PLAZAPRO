import { pipelineApi } from '@/features/pipeline/api'
import { cacheSnapshot } from '@/features/offline/snapshots'

// My Day's offline snapshot: written by MyDayView on every load AND by the
// pre-warm pass (features/offline/prewarm.js) on app open/reconnect, so the
// agent's agenda survives a no-signal morning even before the view was opened.
// The key lives here so the view and the pre-warm can never drift apart.
export const MY_DAY_SNAPSHOT = 'pipeline:my-day'

export function myDaySnapshotShape(data) {
  return { visits: data.visits, route_order: data.route_order ?? [] }
}

// Fetch + snapshot the agenda; returns the raw payload so the pre-warm pass
// can chain into the day's client files.
export async function prewarmMyDay() {
  const data = await pipelineApi.myDay()
  await cacheSnapshot(MY_DAY_SNAPSHOT, myDaySnapshotShape(data))
  return data
}
