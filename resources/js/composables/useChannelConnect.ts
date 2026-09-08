import { useIntegrationsStore, type Provider } from '../stores/integrations';

/** `channel_connect_pending` used to be sessionStorage — written and read within one tab, the same-tab OAuth redirect. It is now localStorage: a fast-connect provider opens the platform's login in a NEW tab (see Channels/Index.vue's `beginConnect()`), and sessionStorage does not cross a tab boundary. */
export const PENDING_CONNECT_KEY = 'channel_connect_pending';

/** How old a pending record may be before pages/Integrations/Callback.vue treats it as absent. localStorage outlives the browser session sessionStorage did, so an abandoned connect must not be picked up by an unrelated visit days later. */
export const PENDING_CONNECT_TTL_MS = 15 * 60 * 1000;

export interface PendingConnect {
    provider: string;
    state: string;
    extra: Record<string, unknown>;
    created_at: number;
}

/** Thrown by `startOAuthConnect` when the browser refused to open the login tab — a popup blocker, not a network or auth failure. Callers show dedicated copy for it rather than the generic connect error. */
export class PopupBlockedError extends Error {
    constructor() {
        super('The browser blocked the login tab.');
        this.name = 'PopupBlockedError';
    }
}

/**
 * The oauth half of the connect flow, shared by ConnectProviderModal.vue
 * (an oauth provider that declares fields to collect first) and
 * Channels/Index.vue (a fast-connect oauth provider that declares none).
 */
export function useChannelConnect() {
    const store = useIntegrationsStore();

    /**
     * Opens the platform's login in a new tab and starts a connect for
     * `provider`, forwarding `params` to `POST /integrations/{provider}/auth-url`
     * exactly like the store's own `connect()` does.
     *
     * The blank tab is opened *synchronously*, before the `await` below:
     * Safari and Firefox only honour `window.open()` inside the click's own
     * user-gesture window, and letting the request resolve first would
     * spend it — the call would silently become a popup block. The tab is
     * navigated once the URL is known, and closed again if the request
     * failed: a stranded `about:blank` tab is worse than no tab.
     */
    async function startOAuthConnect(provider: Provider, params: Record<string, unknown>): Promise<void> {
        const tab = window.open('', '_blank', 'noopener');

        if (!tab) {
            throw new PopupBlockedError();
        }

        let response;

        try {
            response = await store.connect(provider.identifier, params);
        } catch (error) {
            tab.close();
            throw error;
        }

        const { url, state, extra } = response;
        const pending: PendingConnect = { provider: provider.identifier, state, extra, created_at: Date.now() };
        localStorage.setItem(PENDING_CONNECT_KEY, JSON.stringify(pending));
        tab.location.href = url;
    }

    return { startOAuthConnect };
}
