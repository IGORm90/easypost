import axios, { AxiosError, isAxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '../client'
import { useLimitToastStore } from '../../stores/limitToast'

// A fake adapter so `api.post(...)` never hits the network — we only care
// about the request interceptor's CSRF-priming behaviour, not a real
// backend response.
function okAdapter() {
  return Promise.resolve({
    data: {},
    status: 200,
    statusText: 'OK',
    headers: {},
    config: {},
  })
}

async function freshClient() {
  vi.resetModules()
  const { api } = await import('../client')
  // @ts-expect-error -- test-only adapter override, not part of the public config type
  api.defaults.adapter = okAdapter
  return api
}

beforeEach(() => {
  vi.restoreAllMocks()
  setActivePinia(createPinia())
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
describe('402 plan-limit interceptor', () => {
  const message = "You've reached your plan's channel limit (2/2). Disconnect a channel you no longer use, or ask an organization admin to move to a plan with more channels."

  // A bare custom adapter (unlike axios's real xhr/http adapters) does not
  // apply `validateStatus` itself — that check lives inside those real
  // adapters' own `settle()` call, which a raw stand-in like this bypasses
  // entirely. Rejecting with a real `AxiosError` here reproduces exactly
  // what a genuine 402/500 response looks like by the time it reaches the
  // response interceptor, rather than a resolved, never-rejecting promise.
  function respondWith(status: number, statusText: string, data: unknown) {
    return (config: object) =>
      Promise.reject(new AxiosError(`Request failed with status code ${status}`, AxiosError.ERR_BAD_REQUEST, config, null, {
        data,
        status,
        statusText,
        headers: {},
        config,
      }))
  }

  it('shows the warning toast with the server\'s message and still rejects with the original 402 AxiosError', async () => {
    // The request interceptor primes CSRF (a real `axios.get()` call) ahead
    // of every write — stub it out the same way the CSRF-priming test above
    // does, so this test's `api.post()` reaches the adapter below instead
    // of failing on a real network call first.
    vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: {}, status: 204, statusText: 'No Content', headers: {}, config: {} })
    // @ts-expect-error -- test-only adapter override, not part of the public config type
    api.defaults.adapter = respondWith(402, 'Payment Required', { message, limit: 'channels', current: 2, max: 2 })

    let caught: unknown
    try {
      await api.post('/integrations/fake/auth-url')
    } catch (error) {
      caught = error
    }

    expect(isAxiosError(caught)).toBe(true)
    if (isAxiosError(caught)) {
      expect(caught.response?.status).toBe(402)
      expect(caught.response?.data?.message).toBe(message)
    }

    expect(useLimitToastStore().message).toBe(message)
  })

  it('leaves the toast untouched for a non-402 error, so it is never confused with an unrelated failure', async () => {
    vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: {}, status: 204, statusText: 'No Content', headers: {}, config: {} })
    // @ts-expect-error -- test-only adapter override, not part of the public config type
    api.defaults.adapter = respondWith(500, 'Internal Server Error', { message: 'server exploded' })

    await expect(api.post('/things')).rejects.toSatisfy((error: unknown) => isAxiosError(error) && error.response?.status === 500)

    expect(useLimitToastStore().message).toBeNull()
  })
})
