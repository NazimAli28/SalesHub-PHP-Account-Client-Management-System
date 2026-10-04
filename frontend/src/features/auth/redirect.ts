import { paths } from '@/app/paths'

/** Builds `/login?redirect=/leads?stage=new` for the current location. */
export function loginPathFor(location: {
  pathname: string
  search: string
  hash?: string
}): string {
  const target = `${location.pathname}${location.search}${location.hash ?? ''}`
  return target && target !== '/' && !target.startsWith(paths.login)
    ? `${paths.login}?redirect=${encodeURIComponent(target)}`
    : paths.login
}

/** Only accepts same-app paths, so `?redirect=https://evil.example` cannot send users elsewhere. */
export function safeRedirect(value: string | null): string {
  if (!value || !value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) {
    return paths.dashboard
  }
  return value
}
