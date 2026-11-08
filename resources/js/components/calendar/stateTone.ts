/**
 * The card tint for a post state, shared by the calendar's card views
 * (CalendarEvent in the FullCalendar grid, ChannelLanes in the lanes
 * view) so the two can never disagree about what a failed post looks
 * like.
 *
 * «Кобальт» tints a card for the two outcomes only — failed and
 * published. In-flight states stay on the plain surface: a week where
 * every card is coloured stops saying anything (color.md §3.2). A draft
 * gets the firmer border; callers add `border-dashed` as its structural,
 * non-colour signal.
 */
export function stateCardTone(state: string): string {
    switch (state) {
        case 'ERROR':
            return 'border-danger-border bg-danger-soft';
        case 'PUBLISHED':
            return 'border-success-border bg-success-soft';
        case 'DRAFT':
            return 'border-border-strong bg-surface';
        default:
            return 'border-border bg-surface';
    }
}
