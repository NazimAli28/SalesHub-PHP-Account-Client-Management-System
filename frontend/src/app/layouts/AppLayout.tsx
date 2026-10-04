import { Suspense, useEffect, useRef, type RefObject } from 'react'
import { Outlet, useLocation, useNavigation } from 'react-router'
import { PageSkeleton } from '@/components/layout/LoadingSkeleton'
import { Separator } from '@/components/ui/separator'
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
import { cn } from '@/lib/utils'
import { AppBreadcrumb } from './AppBreadcrumb'
import { useCrumbs } from './use-crumbs'
import { AppSidebar } from './AppSidebar'
import { CommandMenu } from './CommandMenu'
import { NotificationsBell } from './NotificationsBell'
import { UserMenu } from './UserMenu'

function readSidebarCookie(): boolean {
  // shadcn's sidebar stores its expanded/collapsed state in this cookie.
  return !document.cookie.split('; ').includes('sidebar_state=false')
}

/** Keeps the tab title in sync and moves focus to the page on navigation (screen readers). */
function useRouteAnnouncements(mainRef: RefObject<HTMLElement | null>) {
  const crumbs = useCrumbs()
  const title = crumbs[crumbs.length - 1]?.label
  const { pathname } = useLocation()
  const firstRender = useRef(true)

  useEffect(() => {
    document.title = title ? `${title} · SalesHub` : 'SalesHub'
  }, [title])

  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false
      return
    }
    mainRef.current?.focus({ preventScroll: true })
  }, [pathname, mainRef])
}

/** The signed-in app shell: sidebar, top bar and the routed page. */
export function AppLayout() {
  const mainRef = useRef<HTMLElement>(null)
  const navigation = useNavigation()
  useRouteAnnouncements(mainRef)

  return (
    <SidebarProvider defaultOpen={readSidebarCookie()}>
      <a
        href="#main-content"
        className="bg-background focus:ring-ring sr-only z-50 rounded-md px-3 py-2 text-sm font-medium shadow focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:ring-2"
      >
        Skip to content
      </a>
      <AppSidebar />
      {/* min-w-0 lets wide content (the leads board, tables) scroll inside the page, not widen it. */}
      <SidebarInset className="min-w-0">
        {/* Thin progress bar while a lazy route chunk loads. */}
        <div
          aria-hidden="true"
          className={cn(
            'bg-primary pointer-events-none fixed inset-x-0 top-0 z-50 h-0.5 origin-left transition-transform duration-500',
            navigation.state === 'loading' ? 'scale-x-75' : 'scale-x-0 opacity-0',
          )}
        />
        <header className="bg-background/80 supports-[backdrop-filter]:bg-background/60 sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b px-4 backdrop-blur">
          <SidebarTrigger className="-ml-1" aria-label="Toggle sidebar" />
          <Separator orientation="vertical" className="mr-1 h-4 data-[orientation=vertical]:h-4" />
          <AppBreadcrumb />
          <div className="ml-auto flex items-center gap-1 sm:gap-2">
            <CommandMenu />
            <NotificationsBell />
            <UserMenu />
          </div>
        </header>
        <main
          id="main-content"
          ref={mainRef}
          tabIndex={-1}
          className="mx-auto w-full max-w-7xl min-w-0 flex-1 px-4 py-6 outline-none sm:px-6 lg:px-8"
        >
          <Suspense fallback={<PageSkeleton />}>
            <Outlet />
          </Suspense>
        </main>
      </SidebarInset>
    </SidebarProvider>
  )
}
