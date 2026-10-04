import { useEffect, useMemo } from 'react'
import { NavLink, useLocation } from 'react-router'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuBadge,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
  useSidebar,
} from '@/components/ui/sidebar'
import { useAuth } from '@/features/auth/AuthProvider'
import { usePendingApprovalsCount } from '@/features/approvals/api'
import { VIEW_ANY } from '@/lib/permissions'
import { NAVIGATION, filterNavigation, type NavItem } from '../navigation'
import { paths } from '../paths'
import { BrandMark } from './BrandMark'

function isActivePath(pathname: string, item: NavItem): boolean {
  if (item.path === paths.dashboard) return pathname === paths.dashboard
  return pathname === item.path || pathname.startsWith(`${item.path}/`)
}

export function AppSidebar() {
  const { satisfies, canAny, user } = useAuth()
  const { pathname } = useLocation()
  const { isMobile, setOpenMobile } = useSidebar()
  const sections = useMemo(() => filterNavigation(NAVIGATION, satisfies), [satisfies])

  const canReview = canAny(VIEW_ANY.reviewApprovals)
  const pendingApprovals = usePendingApprovalsCount({ enabled: canReview })

  // On phones the sidebar is a sheet: close it after navigating.
  useEffect(() => {
    if (isMobile) setOpenMobile(false)
  }, [pathname, isMobile, setOpenMobile])

  const badgeFor = (item: NavItem): number | undefined => {
    if (item.badge === 'pendingApprovals' && canReview)
      return pendingApprovals.data?.reviewable || undefined
    return undefined
  }

  return (
    <Sidebar collapsible="icon" aria-label="Main navigation">
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton size="lg" asChild tooltip="SalesHub">
              <NavLink to={paths.dashboard} end aria-label="SalesHub home">
                <BrandMark />
                <span className="flex flex-col leading-tight">
                  <span className="font-semibold">SalesHub</span>
                  <span className="text-sidebar-foreground/60 truncate text-xs">
                    {user?.team?.name ?? 'Sales operations'}
                  </span>
                </span>
              </NavLink>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      <SidebarContent>
        {sections.map((section) => (
          <SidebarGroup key={section.label}>
            <SidebarGroupLabel>{section.label}</SidebarGroupLabel>
            <SidebarGroupContent>
              <SidebarMenu>
                {section.items.map((item) => {
                  const badge = badgeFor(item)
                  const active = isActivePath(pathname, item)
                  return (
                    <SidebarMenuItem key={item.path}>
                      <SidebarMenuButton asChild isActive={active} tooltip={item.label}>
                        <NavLink to={item.path} end={item.path === paths.dashboard}>
                          <item.icon aria-hidden="true" />
                          <span>{item.label}</span>
                        </NavLink>
                      </SidebarMenuButton>
                      {badge ? (
                        <SidebarMenuBadge
                          className="bg-primary text-primary-foreground peer-hover/menu-button:text-primary-foreground peer-data-active/menu-button:text-primary-foreground"
                          aria-label={`${badge} pending approvals`}
                        >
                          {badge > 99 ? '99+' : badge}
                        </SidebarMenuBadge>
                      ) : null}
                    </SidebarMenuItem>
                  )
                })}
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>
        ))}
      </SidebarContent>

      <SidebarFooter className="text-sidebar-foreground/50 px-4 pb-3 text-xs group-data-[collapsible=icon]:hidden">
        SalesHub v2
      </SidebarFooter>
      <SidebarRail />
    </Sidebar>
  )
}
