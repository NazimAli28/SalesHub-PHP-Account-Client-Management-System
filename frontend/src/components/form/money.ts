/** `"1,234.5"` -> `123450`; empty -> `null`; not a number -> `NaN` (let zod report it). */
export function parseMoneyInput(text: string): number | null {
  const cleaned = text.replace(/[,\s]/g, '')
  if (cleaned === '') return null
  if (!/^\d+(\.\d{0,2})?$/.test(cleaned)) return Number.NaN
  return Math.round(Number(cleaned) * 100)
}

/** `123450` -> `"1234.50"`. */
export function centsToInput(cents: number | null | undefined): string {
  if (cents === null || cents === undefined || Number.isNaN(cents)) return ''
  return (cents / 100).toFixed(2)
}
