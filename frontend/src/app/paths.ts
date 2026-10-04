/** Every URL in the app, in one place. Use these instead of string literals. */
export const paths = {
  login: '/login',
  dashboard: '/',
  profile: '/profile',
  forbidden: '/403',

  leads: '/leads',
  clients: '/clients',
  orders: '/orders',
  payments: '/payments',
  platformAccounts: '/platform-accounts',
  socialAccounts: '/social-accounts',
  approvals: '/approvals',
  notifications: '/notifications',

  users: '/users',
  teams: '/teams',
  workstations: '/workstations',
  services: '/services',
  auditLog: '/audit-log',
} as const

export type AppPath = (typeof paths)[keyof typeof paths]
