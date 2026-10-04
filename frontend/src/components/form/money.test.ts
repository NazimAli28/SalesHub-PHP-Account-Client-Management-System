import { centsToInput, parseMoneyInput } from './money'

describe('money input helpers', () => {
  it.each([
    ['250', 25000],
    ['249.99', 24999],
    ['1,234.5', 123450],
    [' 0.07 ', 7],
    ['', null],
  ])('parses "%s" to %s cents', (input, cents) => {
    expect(parseMoneyInput(input)).toBe(cents)
  })

  it('returns NaN for invalid amounts so validation can flag them', () => {
    expect(parseMoneyInput('12.345')).toBeNaN()
    expect(parseMoneyInput('abc')).toBeNaN()
    expect(parseMoneyInput('-5')).toBeNaN()
  })

  it('formats cents for the input', () => {
    expect(centsToInput(123450)).toBe('1234.50')
    expect(centsToInput(null)).toBe('')
  })
})
