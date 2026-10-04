/** Every URL in the app, in one place. Use these instead of string literals. */
export const paths = {
  login: '/login',
  dashboard: '/',
  profile: '/profile',
  forbidden: '/403',
  today: '/today',

  leads: '/leads',
  clients: '/clients',
  orders: '/orders',
  payments: '/payments',
  imports: '/imports',
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

/** Detail-page URLs. */
export const detailPath = {
  client: (id: number | string) => `${paths.clients}/${id}`,
  order: (id: number | string) => `${paths.orders}/${id}`,
  platformAccount: (id: number | string) => `${paths.platformAccounts}/${id}`,
  approval: (id: number | string) => `${paths.approvals}/${id}`,
} as const
