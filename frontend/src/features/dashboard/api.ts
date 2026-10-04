/** Data hooks and date-range presets for the analytics dashboard. */
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { subDays } from 'date-fns'
import { api } from '@/api/client'
import type { Envelope } from '@/api/types'
import { toIsoDate } from '@/lib/format'
import type { Overview, OverviewParams } from './types'

export const dashboardKeys = {
  all: ['analytics'] as const,
  overview: (params: OverviewParams) => ['analytics', 'overview', params] as const,
}

export const RANGE_PRESETS = [
  { value: '7d', label: '7 days', days: 7, long: 'last 7 days' },
  { value: '30d', label: '30 days', days: 30, long: 'last 30 days' },
  { value: '90d', label: '90 days', days: 90, long: 'last 90 days' },
  { value: '12m', label: '12 months', days: 365, long: 'last 12 months' },
] as const

export type RangePreset = (typeof RANGE_PRESETS)[number]['value']

export const DEFAULT_PRESET: RangePreset = '30d'

export function isRangePreset(value: string | null): value is RangePreset {
  return RANGE_PRESETS.some((preset) => preset.value === value)
}

export function presetInfo(preset: RangePreset) {
  return RANGE_PRESETS.find((p) => p.value === preset) ?? RANGE_PRESETS[1]
}

/** The preset's window, ending today. */
export function presetRange(preset: RangePreset, today = new Date()) {
  return { from: toIsoDate(subDays(today, presetInfo(preset).days - 1)), to: toIsoDate(today) }
}

export function useOverview(params: OverviewParams) {
  return useQuery({
    queryKey: dashboardKeys.overview(params),
    queryFn: ({ signal }) => {
      const query: Record<string, string | number> = { from: params.from, to: params.to }
      if (params.teamId) query.team_id = params.teamId
      return api.get<Envelope<Overview>>('/analytics/overview', { query, signal })
    },
    select: (response) => response.data,
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  })
}
