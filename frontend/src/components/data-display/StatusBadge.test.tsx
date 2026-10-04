import { render, screen } from '@testing-library/react'
import { TooltipProvider } from '@/components/ui/tooltip'
import { STATUS_ENUMS, type StatusKind } from '@/lib/enums'
import { StatusBadge } from './StatusBadge'

function renderBadge(kind: StatusKind, value: Parameters<typeof StatusBadge>[0]['value']) {
  return render(
    <TooltipProvider>
      <StatusBadge kind={kind} value={value} />
    </TooltipProvider>,
  )
}

describe('StatusBadge', () => {
  it.each([
    ['leadStage', 'won', 'Won', 'success'],
    ['leadStage', 'lost', 'Lost', 'danger'],
    ['leadStage', 'payment_pending', 'Payment Pending', 'warning'],
    ['accountStanding', 'limited', 'Limited', 'warning'],
    ['accountStanding', 'disabled', 'Disabled', 'muted'],
    ['paymentStatus', 'paid', 'Paid', 'success'],
    ['approvalStatus', 'pending', 'Pending', 'warning'],
    ['approvalStatus', 'rejected', 'Rejected', 'danger'],
  ] as const)('maps %s "%s" to label "%s" and tone %s', (kind, value, label, tone) => {
    renderBadge(kind, value)
    expect(screen.getByText(label).closest('[data-tone]')).toHaveAttribute('data-tone', tone)
  })

  it('prefers the label the API sent', () => {
    renderBadge('leadStage', { value: 'portfolio_shared', label: 'Portfolio shared (custom)' })
    expect(screen.getByText('Portfolio shared (custom)').closest('[data-tone]')).toHaveAttribute(
      'data-tone',
      'info',
    )
  })

  it('falls back to neutral for unknown values and renders nothing for null', () => {
    const { container } = renderBadge('orderStatus', 'archived')
    expect(screen.getByText('archived').closest('[data-tone]')).toHaveAttribute(
      'data-tone',
      'neutral',
    )
    container.remove()
    const empty = renderBadge('orderStatus', null)
    expect(empty.container).toBeEmptyDOMElement()
  })

  it('gives every enum value a tone', () => {
    for (const definition of Object.values(STATUS_ENUMS)) {
      for (const option of definition.options) {
        expect((definition.tones as Record<string, string>)[option.value]).toBeTruthy()
      }
    }
  })
})
