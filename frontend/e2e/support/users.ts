import path from 'node:path'

export const DEMO_PASSWORD = 'Demo@12345'

export type Role = 'admin' | 'support' | 'tl' | 'agent1'

export const ROLES: Record<Role, { label: string; login: string }> = {
  admin: { label: 'Admin', login: 'admin' },
  support: { label: 'Support', login: 'support' },
  tl: { label: 'Team Lead', login: 'tl' },
  agent1: { label: 'Sales Executive', login: 'agent1' },
}

/** Where the setup project saves each role's signed-in browser state. */
export const storageStatePath = (role: Role) => path.join('e2e', '.auth', `${role}.json`)
