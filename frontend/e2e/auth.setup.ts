import { test as setup, expect } from '@playwright/test'
import { ROLES, storageStatePath, type Role } from './support/users.ts'

// Signs in once per role through the demo quick-login and saves the session for the specs.
for (const role of Object.keys(ROLES) as Role[]) {
  setup(`sign in as ${role}`, async ({ page }) => {
    await page.goto('/login')
    await page.getByRole('button', { name: `Sign in as ${ROLES[role].label}` }).click()
    await expect(
      page.getByRole('heading', { level: 1, name: /^Good (morning|afternoon|evening),/ }),
    ).toBeVisible()
    await page.context().storageState({ path: storageStatePath(role) })
  })
}
