/**
 * Query-key factories. Every resource gets the same hierarchical shape, so invalidation is
 * predictable:
 *
 *   leadKeys.all            ['leads']                     everything about leads
 *   leadKeys.lists()        ['leads', 'list']             every list variant
 *   leadKeys.list(params)   ['leads', 'list', params]     one filtered/sorted page
 *   leadKeys.details()      ['leads', 'detail']
 *   leadKeys.detail(7)      ['leads', 'detail', 7]
 *
 * After a write, invalidate `keys.all` (simple and always correct) or a narrower level.
 */
export function createQueryKeys<const TResource extends string>(resource: TResource) {
  const all = [resource] as const
  return {
    all,
    lists: () => [...all, 'list'] as const,
    list: <TParams extends object>(params: TParams) => [...all, 'list', params] as const,
    details: () => [...all, 'detail'] as const,
    detail: (id: number | string) => [...all, 'detail', id] as const,
  }
}
