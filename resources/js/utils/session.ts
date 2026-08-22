/**
 * Hard-restart the SPA after the server has swapped the session cookie
 * underneath it — impersonation start and stop, Task 1.7.
 *
 * A reload rather than a targeted `fetchMe()` for two reasons, both
 * about state that outlives the call:
 *
 *  - `organization_id` in localStorage belongs to the *previous* user.
 *    The API client's request interceptor attaches it as
 *    `X-Organization` on every request, so leaving it in place sends the
 *    next session at a workspace it is not a member of and 403s it. It is
 *    cleared here first; `syncFromAuth()` picks a valid one on the way
 *    back up.
 *  - Every store still holds the previous user's channels, media and
 *    posts. Refreshing one of them and trusting the rest to notice is a
 *    long tail of stale-data bugs; starting from nothing has none.
 *
 * Extracted into its own module so components can be tested without
 * fighting happy-dom over a read-only `window.location`.
 */
export function restartSession(): void {
    localStorage.removeItem('organization_id');
    window.location.reload();
}
