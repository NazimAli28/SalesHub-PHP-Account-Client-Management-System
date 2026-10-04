import { z } from 'zod'
import type { ServiceCategory } from '@/api/types'
import type { Service } from './api'

const CATEGORIES = [
  'branding',
  'emotes',
  'overlays',
  'packages',
  'animation',
  'other',
] as const satisfies readonly ServiceCategory[]

/** Mirrors backend/app/Http/Requests/Services (the API's 422 messages land on these fields). */
export const serviceFormSchema = z
  .object({
    name: z.string().trim().min(1, 'Enter a name.').max(80, 'Keep it under 80 characters.'),
    /** Empty on create = the API slugifies the name. */
    slug: z
      .string()
      .trim()
      .max(80, 'Keep it under 80 characters.')
      .refine(
        (value) => value === '' || /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value),
        'Use lowercase letters, numbers and single dashes.',
      ),
    category: z.enum(CATEGORIES, { error: 'Choose a category.' }),
    description: z.string().max(2000, 'Keep it under 2,000 characters.'),
    base_price_cents: z
      .number({ error: 'Enter a price.' })
      .nullable()
      .refine(
        (value) => value === null || Number.isFinite(value),
        'Enter an amount like 250 or 249.99.',
      )
      .refine(
        (value) => value === null || (value >= 0 && value <= 100_000_000),
        'Enter an amount up to $1,000,000.',
      ),
    is_active: z.boolean(),
  })
  .superRefine((values, context) => {
    if (values.base_price_cents === null) {
      context.addIssue({ code: 'custom', path: ['base_price_cents'], message: 'Enter a price.' })
    }
  })

export type ServiceFormValues = z.infer<typeof serviceFormSchema>

export interface ServicePayload {
  name: string
  slug?: string
  category: ServiceCategory
  description: string | null
  base_price_cents: number
  is_active: boolean
}

export function serviceFormDefaults(service?: Service | null): ServiceFormValues {
  return {
    name: service?.name ?? '',
    slug: service?.slug ?? '',
    category: (service?.category?.value as ServiceCategory | undefined) ?? 'branding',
    description: service?.description ?? '',
    base_price_cents: service?.base_price?.amount_cents ?? null,
    is_active: service?.is_active ?? true,
  }
}

export function toServicePayload(values: ServiceFormValues): ServicePayload {
  return {
    name: values.name.trim(),
    slug: values.slug.trim() || undefined,
    category: values.category,
    description: values.description.trim() || null,
    base_price_cents: values.base_price_cents!,
    is_active: values.is_active,
  }
}
