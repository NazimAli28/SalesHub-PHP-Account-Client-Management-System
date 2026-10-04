import { NAVIGATION, filterNavigation } from '@/app/navigation'
import { SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { PERMISSIONS, VIEW_ANY, createPermissionChecker } from './permissions'

describe('createPermissionChecker', () => {
  const { can, canAny, canAll, satisfies } = createPermissionChecker([
    'leads.view-own',
    'leads.create',
  ])

  it('checks single permissions', () => {
    expect(can('leads.view-own')).toBe(true)
    expect(can('leads.delete')).toBe(false)
  })

  it('checks any / all of a list', () => {
    expect(canAny(VIEW_ANY.leads)).toBe(true)
    expect(canAny(['users.view', 'audit-log.view'])).toBe(false)
    expect(canAll(['leads.view-own', 'leads.create'])).toBe(true)
    expect(canAll(['leads.view-own', 'leads.update'])).toBe(false)
  })

  it('treats an undefined requirement as public and a list as "any of"', () => {
    expect(satisfies(undefined)).toBe(true)
    expect(satisfies('leads.create')).toBe(true)
    expect(satisfies(VIEW_ANY.clients)).toBe(false)
  })

  it('denies everything when signed out', () => {
    const signedOut = createPermissionChecker(undefined)
    expect(signedOut.can('dashboard.view')).toBe(false)
    expect(signedOut.canAny(PERMISSIONS)).toBe(false)
  })
})

describe('filterNavigation', () => {
  const labels = (permissions: readonly string[]) =>
    filterNavigation(NAVIGATION, createPermissionChecker(permissions).satisfies).flatMap(
      (section) => section.items.map((item) => item.label),
    )

  it('shows an admin every item', () => {
    const all = NAVIGATION.flatMap((section) => section.items.map((item) => item.label))
    expect(labels(PERMISSIONS)).toEqual(all)
  })

  it('hides admin-only items from a sales executive', () => {
    const visible = labels(SALES_EXECUTIVE_PERMISSIONS)
    expect(visible).toContain('Leads')
    expect(visible).toContain('Approvals')
    expect(visible).not.toContain('Users')
    expect(visible).not.toContain('Audit log')
  })

  it('drops sections with no visible items', () => {
    const sections = filterNavigation(
      NAVIGATION,
      createPermissionChecker(['dashboard.view']).satisfies,
    )
    expect(sections.map((section) => section.label)).toEqual(['Overview'])
    expect(sections[0]?.items.map((item) => item.label)).toEqual(['Dashboard'])
  })
})
