import axios from 'axios'

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
