// Generated from the PHP Dto classes by bin/dtoExport - do not edit.
//
// Regenerate with "composer types:export" in the Orange-Application repo
// after changing a Dto. CI fails when this file and the Dto classes
// disagree, so it cannot quietly fall behind.

/** Generated from application\api\models\CalendarEventDto. */
export interface CalendarEvent {
  id: number
  title: string
  description: string
  date: string
}

export type CalendarEventInput = Omit<CalendarEvent, 'id'>

/** Generated from application\api\models\RecordDto. */
export interface RecordItem {
  id: number
  name: string
  phone: string
  in_office: boolean
  out_until: string | null
}

export type RecordInput = Omit<RecordItem, 'id'>

/** Generated from application\login\models\ResetPasswordDto. */
export interface ResetPassword {
  password: string
  passwordConfirm: string
}

/** Generated from application\login\models\SignupDto. */
export interface Signup {
  username: string
  email: string
  password: string
  passwordConfirm: string
}

/** Generated from application\orders\models\CustomerDto. */
export interface Customer {
  id: number
  name: string
  email: string
  phone: string
}

export type CustomerInput = Omit<Customer, 'id'>

/** Generated from application\orders\models\LineItemDto. */
export interface LineItem {
  id: number
  sku: string
  description: string
  qty: number
  unit_price: number
  line_total: number
}

export type LineItemInput = Omit<LineItem, 'id'>

/** Generated from application\orders\models\OrderDto. */
export interface Order {
  id: number
  customer_id: number
  ordered_on: string
  notes: string
  lines: LineItem[]
}

export type OrderInput = Omit<Order, 'id' | 'lines'> & { lines: LineItemInput[] }
