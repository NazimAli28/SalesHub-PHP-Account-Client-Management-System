/**
 * A small Laravel-style validator for the browser demo: the same field keys and messages as the
 * Form Requests, for the rules the screens can trigger.
 */
import { validationError, type Json } from './http'
import { enumValues } from './present'

const DATE = /^\d{4}-\d{2}-\d{2}$/
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function attribute(field: string): string {
  return field.replace(/\./g, ' ').replace(/_/g, ' ')
}

export class Validator {
  readonly errors: Record<string, string[]> = {}
  readonly data: Json

  constructor(data: Json) {
    this.data = data
  }

  has(field: string): boolean {
    return Object.prototype.hasOwnProperty.call(this.data, field)
  }

  value(field: string): unknown {
    return this.data[field]
  }

  filled(field: string): boolean {
    const value = this.data[field]
    return value !== undefined && value !== null && value !== ''
  }

  add(field: string, message: string): this {
    ;(this.errors[field] ??= []).push(message)
    return this
  }

  failed(field: string): boolean {
    return this.errors[field] !== undefined
  }

  required(field: string): this {
    const value = this.data[field]
    const empty =
      value === undefined ||
      value === null ||
      (typeof value === 'string' && value.trim() === '') ||
      (Array.isArray(value) && value.length === 0)
    if (empty) this.add(field, `The ${attribute(field)} field is required.`)
    return this
  }

  /** `sometimes|required`: required only when the field is sent. */
  requiredIfPresent(field: string): this {
    return this.has(field) ? this.required(field) : this
  }

  prohibited(field: string, message?: string): this {
    if (this.has(field)) this.add(field, message ?? `The ${attribute(field)} field is prohibited.`)
    return this
  }

  string(field: string, max: number): this {
    if (!this.filled(field) || this.failed(field)) return this
    const value = this.data[field]
    if (typeof value !== 'string')
      return this.add(field, `The ${attribute(field)} field must be a string.`)
    if (value.length > max) {
      this.add(field, `The ${attribute(field)} field must not be greater than ${max} characters.`)
    }
    return this
  }

  integer(field: string, min?: number, max?: number): this {
    if (!this.filled(field) || this.failed(field)) return this
    const value = Number(this.data[field])
    if (!Number.isInteger(value)) {
      return this.add(field, `The ${attribute(field)} field must be an integer.`)
    }
    if (min !== undefined && value < min) {
      this.add(field, `The ${attribute(field)} field must be at least ${min}.`)
    } else if (max !== undefined && value > max) {
      this.add(field, `The ${attribute(field)} field must not be greater than ${max}.`)
    }
    return this
  }

  boolean(field: string): this {
    if (!this.has(field) || this.failed(field)) return this
    const value = this.data[field]
    if (![true, false, 0, 1, '0', '1'].includes(value as never)) {
      this.add(field, `The ${attribute(field)} field must be true or false.`)
    }
    return this
  }

  date(field: string): this {
    if (!this.filled(field) || this.failed(field)) return this
    const value = this.data[field]
    if (typeof value !== 'string' || !DATE.test(value) || Number.isNaN(Date.parse(value))) {
      this.add(field, `The ${attribute(field)} field must match the format Y-m-d.`)
    }
    return this
  }

  /** Date not after `today` (LocalToday::notFuture, lenient by one day for eastern time zones). */
  notFuture(field: string, today: string): this {
    if (!this.filled(field) || this.failed(field)) return this
    const value = String(this.data[field])
    const limit = new Date(Date.parse(`${today}T00:00:00Z`) + 86_400_000).toISOString().slice(0, 10)
    if (value > limit) {
      this.add(field, `The ${attribute(field)} field must be a date before or equal to today.`)
    }
    return this
  }

  email(field: string): this {
    if (!this.filled(field) || this.failed(field)) return this
    if (!EMAIL.test(String(this.data[field]))) {
      this.add(field, `The ${attribute(field)} field must be a valid email address.`)
    }
    return this
  }

  enum(field: string, enumName: string, except: string[] = []): this {
    if (!this.filled(field) || this.failed(field)) return this
    const value = String(this.data[field])
    if (!enumValues(enumName).includes(value) || except.includes(value)) {
      this.add(field, `The selected ${attribute(field)} is invalid.`)
    }
    return this
  }

  in(field: string, values: string[], message?: string): this {
    if (!this.filled(field) || this.failed(field)) return this
    if (!values.includes(String(this.data[field]))) {
      this.add(field, message ?? `The selected ${attribute(field)} is invalid.`)
    }
    return this
  }

  regex(field: string, pattern: RegExp): this {
    if (!this.filled(field) || this.failed(field)) return this
    if (!pattern.test(String(this.data[field]))) {
      this.add(field, `The ${attribute(field)} field format is invalid.`)
    }
    return this
  }

  /** `exists` / `VisibleTo`: the referenced record must exist (and be in scope). */
  exists(field: string, check: (id: number) => boolean): this {
    if (!this.filled(field) || this.failed(field)) return this
    const id = Number(this.data[field])
    if (!Number.isInteger(id) || !check(id)) {
      this.add(field, `The selected ${attribute(field)} is invalid.`)
    }
    return this
  }

  unique(field: string, taken: (value: string) => boolean): this {
    if (!this.filled(field) || this.failed(field)) return this
    if (taken(String(this.data[field]))) {
      this.add(field, `The ${attribute(field)} has already been taken.`)
    }
    return this
  }

  /** Throws the 422 response when any rule failed. */
  validate(): void {
    if (Object.keys(this.errors).length > 0) throw validationError(this.errors)
  }

  /** The sent values of the given fields. */
  only(fields: string[]): Json {
    const out: Json = {}
    for (const field of fields) if (this.has(field)) out[field] = this.data[field]
    return out
  }
}
