import axios, { AxiosError, isAxiosError } from 'axios'
import type { InternalAxiosRequestConfig } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '../client'
import { useLimitToastStore } from '../../stores/limitToast'
import { i18n } from '../../i18n'

const push = vi.fn()

vi.mock('../../router', () => ({
  default: { push: (...args: unknown[]) => push(...args) },
}))

// A fake adapter so `api.post(...)` never hits the network — we only care
// about the request interceptor's CSRF-priming behaviour, not a real
// backend response.
function okAdapter(config: InternalAxiosRequestConfig) {
  return Promise.resolve({
    data: {},
    status: 200,
    statusText: 'OK',
    headers: {},
    config,
  })
}

async function freshClient() {
  vi.resetModules()
  const { api } = await import('../client')
  api.defaults.adapter = okAdapter
  return api
}

beforeEach(() => {
  vi.restoreAllMocks()
  setActivePinia(createPinia())
  push.mockClear()
  localStorage.clear()
})

it('does not permanently poison writes after a failed CSRF priming request', async () => {
  const getSpy = vi
    .spyOn(axios, 'get')
    .mockRejectedValueOnce(new Error('network blip'))
    .mockResolvedValueOnce({ data: {}, status: 204, statusText: 'No Content', headers: {}, config: {} })

  const api = await freshClient()

  await expect(api.post('/things')).rejects.toThrow('network blip')
  await expect(api.post('/things')).resolves.toMatchObject({ status: 200 })

  // Priming was retried, not served from a poisoned cached rejection.
  expect(getSpy).toHaveBeenCalledTimes(2)
  expect(getSpy).toHaveBeenCalledWith('/sanctum/csrf-cookie')
})

// Task 36: a 402 (PlanLimitExceededException's {message, limit, current,
// max} body) must show the global warning toast AND must not be swallowed
// or reshaped into some other error — a caller checking
// `error.response?.status` must still see the real 402, exactly as the
// backend sent it, never something this interceptor turned into an
// "unknown error" by losing the original response along the way. Uses the
// module-level `api` imported statically at the top of this file (NOT
// freshClient()'s dynamically re-imported one) so its interceptor closes
// over the exact same `stores/limitToast` module instance this file's
// `useLimitToastStore` import also resolves to — see this suite's other
// tests for why mixing `vi.resetModules()` with a statically-held Pinia
// store reference would risk two disconnected module instances instead.
//
// Round 1 review, Important 3: the toast must show a translated,
// `resources/js/i18n/{en,ru}.json`-keyed message built from the body's
// `{limit, current, max}` fields — never the server's raw English
// `message` verbatim — whenever `limit` names a key this app knows about.
// The server string remains the fallback only when it doesn't (an
// unrecognised/future `limit` value).
describe('402 plan-limit interceptor', () => {
  // Deliberately NOT the current en.json wording — proves the toast is
  // built from the i18n key, not merely coincidentally identical to
  // whatever English text the server happens to send this version.
  const serverMessage = "You've reached your plan's channel limit (2/2). Disconnect a channel you no longer use, or ask an organization admin to move to a plan with more channels."

  // A bare custom adapter (unlike axios's real xhr/http adapters) does not
  // apply `validateStatus` itself — that check lives inside those real
  // adapters' own `settle()` call, which a raw stand-in like this bypasses
  // entirely. Rejecting with a real `AxiosError` here reproduces exactly
  // what a genuine 402/500 response looks like by the time it reaches the
  // response interceptor, rather than a resolved, never-rejecting promise.
  function respondWith(status: number, statusText: string, data: unknown) {
    return (config: InternalAxiosRequestConfig) =>
      Promise.reject(new AxiosError(`Request failed with status code ${status}`, AxiosError.ERR_BAD_REQUEST, config, null, {
        data,
        status,
        statusText,
        headers: {},
        config,
      }))
  }

  beforeEach(() => {
    i18n.global.locale.value = 'en'
  })

  it('shows the i18n-keyed, interpolated channel-limit message (not the raw server string) and still rejects with the original 402 AxiosError', async () => {
    // The request interceptor primes CSRF (a real `axios.get()` call) ahead
    // of every write — stub it out the same way the CSRF-priming test above
    // does, so this test's `api.post()` reaches the adapter below instead
    // of failing on a real network call first.
    vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: {}, status: 204, statusText: 'No Content', headers: {}, config: {} })
    api.defaults.adapter = respondWith(402, 'Payment Required', { message: serverMessage, limit: 'channels', current: 2, max: 2 })

    let caught: unknown
    try {
      await api.post('/integrations/fake/auth-url')
    } catch (error) {
      caught = error
    }

    expect(isAxiosError(caught)).toBe(true)
    if (isAxiosError(caught)) {
      expect(caught.response?.status).toBe(402)
      // The original response is untouched — a caller with its own
      // status-specific handling still sees the real server message.
      expect(caught.response?.data?.message).toBe(serverMessage)
    }

    expect(useLimitToastStore().message).toBe(i18n.global.t('limits.channels', { current: 2, max: 2 }))
    expect(useLimitToastStore().message).not.toBe(serverMessage)
  })

  it('falls back to the server\'s own message when `limit` names a key this app does not recognise', async () => {
    vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: {}, status: 204, statusText: 'No Content', headers: {}, config: {} })
    api.defaults.adapter = respondWith(402, 'Payment Required', { message: 'a brand new limit type', limit: 'some_future_limit', current: 1, max: 1 })

    await expect(api.post('/things')).rejects.toSatisfy((error: unknown) => isAxiosError(error) && error.response?.status === 402)

    expect(useLimitToastStore().message).toBe('a brand new limit type')
  })

  it('leaves the toast untouched for a non-402 error, so it is never confused with an unrelated failure', async () => {
    vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: {}, status: 204, statusText: 'No Content', headers: {}, config: {} })
    api.defaults.adapter = respondWith(500, 'Internal Server Error', { message: 'server exploded' })

    await expect(api.post('/things')).rejects.toSatisfy((error: unknown) => isAxiosError(error) && error.response?.status === 500)

    expect(useLimitToastStore().message).toBeNull()
  })
})

// Final review, Critical 3: nothing in the SPA handled 401 or 419.
//
// A 401 mid-session left a signed-in-looking shell where every page
// rendered its own generic "could not load" — the router guard calls
// fetchMe() only while the store is not `ready`, which it is after the
// first navigation — and never told the user to sign in again.
//
// A 419 was worse: `csrfReady` memoises the priming promise for the app's
// lifetime and was dropped only when priming itself rejected. Once the
// session's CSRF token expired in an open tab, the resolved promise made
// every write reuse the dead token, so Save failed identically forever.
describe('session-expiry interceptor', () => {
  function rejectWith(status: number) {
    return (config: InternalAxiosRequestConfig) =>
      Promise.reject(new AxiosError(`Request failed with status code ${status}`, AxiosError.ERR_BAD_REQUEST, config, null, {
        data: {},
        status,
        statusText: '',
        headers: {},
        config,
      }))
  }

  it('re-primes CSRF and retries the write exactly once on a 419', async () => {
    const getSpy = vi
      .spyOn(axios, 'get')
      .mockResolvedValue({ data: {}, status: 204, statusText: 'No Content', headers: {}, config: {} })

    const client = await freshClient()
    let calls = 0
    client.defaults.adapter = (config: InternalAxiosRequestConfig) => {
      calls += 1

      return calls === 1 ? rejectWith(419)(config) : okAdapter(config)
    }

    await expect(client.post('/posts')).resolves.toMatchObject({ status: 200 })

    expect(calls).toBe(2)
    // Priming ran twice: once for the original write, once because the 419
    // dropped the memoised promise. A stale token is never reused.
    expect(getSpy).toHaveBeenCalledTimes(2)
    expect(getSpy).toHaveBeenCalledWith('/sanctum/csrf-cookie')
  })

  it('gives up after one 419 retry instead of looping', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue({ data: {}, status: 204, statusText: 'No Content', headers: {}, config: {} })

    const client = await freshClient()
    let calls = 0
    client.defaults.adapter = (config: InternalAxiosRequestConfig) => {
      calls += 1

      return rejectWith(419)(config)
    }

    await expect(client.post('/posts')).rejects.toSatisfy((error: unknown) => isAxiosError(error) && error.response?.status === 419)
    expect(calls).toBe(2)
  })

  it('on a 401 clears the auth store, flags the expiry and sends the user to login', async () => {
    const { useAuthStore } = await import('../../stores/auth')
    const auth = useAuthStore()
    auth.$patch({ user: { id: 'u-1' } as never, organizations: [{ id: 'org-1' } as never], ready: true })
    localStorage.setItem('organization_id', 'org-1')

    api.defaults.adapter = rejectWith(401)

    await expect(api.get('/posts')).rejects.toSatisfy((error: unknown) => isAxiosError(error) && error.response?.status === 401)

    expect(auth.user).toBeNull()
    expect(auth.organizations).toEqual([])
    expect(auth.sessionExpired).toBe(true)
    expect(push).toHaveBeenCalledWith({ name: 'login' })
  })

  it('leaves a 401 from /auth/me alone — "no session yet" is that endpoint\'s normal answer', async () => {
    const { useAuthStore } = await import('../../stores/auth')
    const auth = useAuthStore()

    api.defaults.adapter = rejectWith(401)

    await expect(api.get('/auth/me')).rejects.toSatisfy((error: unknown) => isAxiosError(error) && error.response?.status === 401)

    expect(auth.sessionExpired).toBe(false)
    expect(push).not.toHaveBeenCalled()
  })
})
