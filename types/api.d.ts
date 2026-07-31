// Generated from the PHP Dto classes by bin/dtoExport - do not edit.
//
// Regenerate with "composer types:export" in the Orange-Application repo
// after changing a Dto. CI fails when this file and the Dto classes
// disagree, so it cannot quietly fall behind.

/** Generated from api\models\CalendarEventDto. */
export interface CalendarEvent {
  id: number
  title: string
  description: string
  date: string
}

export type CalendarEventInput = Omit<CalendarEvent, 'id'>

/** Generated from api\models\RecordDto. */
export interface RecordItem {
  id: number
  name: string
  phone: string
  in_office: boolean
  out_until: string | null
}

export type RecordInput = Omit<RecordItem, 'id'>
