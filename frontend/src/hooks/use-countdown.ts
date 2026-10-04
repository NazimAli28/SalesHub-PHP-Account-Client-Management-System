import { useEffect, useState } from 'react'

/**
 * Seconds left until `endsAt` (a `Date.now()`-style timestamp), updated a few times a second.
 * Returns 0 when `endsAt` is null or has passed.
 */
export function useCountdown(endsAt: number | null): number {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (endsAt === null) return
    const tick = () => setNow(Date.now())
    const timer = window.setInterval(() => {
      tick()
      if (Date.now() >= endsAt) window.clearInterval(timer)
    }, 250)
    const first = window.setTimeout(tick, 0)
    return () => {
      window.clearInterval(timer)
      window.clearTimeout(first)
    }
  }, [endsAt])

  return endsAt === null ? 0 : Math.max(0, Math.ceil((endsAt - now) / 1000))
}
