import { screen } from '@testing-library/react'
import { makeUser } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { DemoBanner } from './DemoBanner'

describe('DemoBanner', () => {
  beforeEach(() => window.sessionStorage.clear())

  it('is hidden outside demo mode', () => {
    renderWithProviders(<DemoBanner />, { user: makeUser({ demo_mode: false }) })
    expect(screen.queryByRole('complementary', { name: 'Demo mode' })).not.toBeInTheDocument()
  })

  it('shows in demo mode and stays dismissed for the session', async () => {
    const { user, unmount } = renderWithProviders(<DemoBanner />, {
      user: makeUser({ demo_mode: true }),
    })
    const banner = screen.getByRole('complementary', { name: 'Demo mode' })
    expect(banner).toHaveTextContent('data resets every hour')

    await user.click(screen.getByRole('button', { name: 'Dismiss demo notice' }))
    expect(screen.queryByRole('complementary', { name: 'Demo mode' })).not.toBeInTheDocument()
    unmount()

    renderWithProviders(<DemoBanner />, { user: makeUser({ demo_mode: true }) })
    expect(screen.queryByRole('complementary', { name: 'Demo mode' })).not.toBeInTheDocument()
  })
})
