<script setup lang="ts">
import { isAxiosError } from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../layouts/AppLayout.vue';
import Badge from '../../components/ui/Badge.vue';
import Button from '../../components/ui/Button.vue';
import Card from '../../components/ui/Card.vue';
import Input from '../../components/ui/Input.vue';
import Toast from '../../components/ui/Toast.vue';
import { api } from '../../api/client';
import { restartSession } from '../../utils/session';

/**
 * The instance-wide user list, and the only place impersonation can be
 * started from (Task 1.7). Before this screen a superadmin had to already
 * know a user's id and POST to /api/admin/impersonate/{user} by hand.
 *
 * Not organization-scoped, on purpose: picking whom to impersonate means
 * looking outside your own workspace, so this reads `GET /admin/users`,
 * which carries no tenant middleware. Reachable only to a holder of the
 * instance-level `is_superadmin` flag — enforced by the `superadmin`
 * middleware server-side and mirrored by the router guard, which is a
 * courtesy to the user, never the security boundary.
 *
 * Rows accumulate page by page rather than being replaced, the way
 * stores/media.ts consumes its own paginated index; a new search starts
 * the list over.
 */
interface AdminUser {
    id: string;
    name: string;
    email: string;
    is_superadmin: boolean;
    email_verified_at: string | null;
    created_at: string;
    organizations: string[];
}

interface PageMeta {
    current_page: number;
    last_page: number;
}

const { t, locale } = useI18n();

const users = ref<AdminUser[]>([]);
const search = ref('');
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const busyId = ref<string | null>(null);
const error = ref<string | null>(null);
/** Set when the list itself could not be read, so an empty table is not mistaken for "nobody matches". */
const loadFailed = ref(false);

const hasMore = computed(() => page.value < lastPage.value);

async function load(nextPage: number, { append = false } = {}): Promise<void> {
    loading.value = true;

    try {
        const { data } = await api.get<{ data: AdminUser[]; meta: PageMeta }>('/admin/users', {
            params: { page: nextPage, search: search.value.trim() || undefined },
        });

        users.value = append ? [...users.value, ...data.data] : data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        loadFailed.value = false;
        error.value = null;
    } catch {
        loadFailed.value = true;
        error.value = t('admin.users.loadError');
    } finally {
        loading.value = false;
    }
}

onMounted(() => load(1));

/**
 * Server-side, not a filter over the page already in hand: the list is
 * paginated, so filtering locally would search 25 of however many
 * thousand accounts exist and quietly report nothing for the rest.
 *
 * Debounced because of that. Querying per keystroke turned "demo@" into
 * six full paginated queries, and — worse than the load — their answers
 * can arrive out of order, leaving the table showing results for a
 * prefix of what is actually in the box.
 */
const SEARCH_DEBOUNCE_MS = 300;
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), SEARCH_DEBOUNCE_MS);
});

onBeforeUnmount(() => clearTimeout(searchTimer));

function formatDate(iso: string): string {
    return new Intl.DateTimeFormat(locale.value, { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(iso));
}

/**
 * The endpoint's refusals mean genuinely different things and only one
 * of them is the user's to fix, so they get separate sentences rather
 * than one shrug:
 *
 *  - 403 from EnsureSuperadmin — you may not do this at all.
 *  - 403 from ImpersonationService — you are already impersonating, and
 *    the fix is to go back to your own account first.
 *  - 422 — that is your own account.
 */
function impersonationError(e: unknown): string {
    if (!isAxiosError(e)) {
        return t('admin.errors.generic');
    }

    if (e.response?.status === 422) {
        return t('admin.errors.self');
    }

    if (e.response?.status === 403) {
        return String(e.response?.data?.message ?? '').includes('from within one')
            ? t('admin.errors.nested')
            : t('admin.errors.forbidden');
    }

    return t('admin.errors.generic');
}

async function impersonate(user: AdminUser): Promise<void> {
    if (busyId.value) {
        return;
    }

    busyId.value = user.id;
    error.value = null;

    try {
        await api.post(`/admin/impersonate/${user.id}`);
        // The session cookie now authenticates as this user, and every
        // store still holds the previous one's data — see
        // restartSession()'s docblock for why this is a full reload.
        restartSession();
    } catch (e) {
        error.value = impersonationError(e);
    } finally {
        busyId.value = null;
    }
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex max-w-4xl flex-col gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-lg font-semibold text-text">{{ t('admin.users.title') }}</h1>
                <p class="text-sm text-muted">{{ t('admin.users.intro') }}</p>
            </div>

            <Card>
                <div class="flex flex-col gap-4">
                    <Toast v-if="error" variant="danger" @dismiss="error = null">{{ error }}</Toast>

                    <div data-test="admin-user-search">
                        <Input v-model="search" type="search" :label="t('admin.users.search')" />
                    </div>

                    <p v-if="loading && users.length === 0" class="text-sm text-muted">{{ t('ui.loading') }}</p>

                    <div v-else-if="!loadFailed && users.length === 0" class="flex flex-col gap-1">
                        <p class="text-sm text-text">{{ t('admin.users.empty') }}</p>
                        <p class="text-sm text-muted">{{ t('admin.users.emptyHint') }}</p>
                    </div>

                    <div v-else-if="users.length > 0" class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-border bg-surface-2 text-muted">
                                <tr>
                                    <th class="px-3 py-2 font-medium">{{ t('admin.users.columnName') }}</th>
                                    <th class="px-3 py-2 font-medium">{{ t('admin.users.columnEmail') }}</th>
                                    <th class="px-3 py-2 font-medium">{{ t('admin.users.columnOrganizations') }}</th>
                                    <th class="px-3 py-2 font-medium">{{ t('admin.users.columnCreated') }}</th>
                                    <th class="px-3 py-2"><span class="sr-only">{{ t('admin.users.impersonate') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="user in users"
                                    :key="user.id"
                                    data-test="admin-user-row"
                                    class="border-b border-border last:border-0"
                                >
                                    <td class="px-3 py-2">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="text-text">{{ user.name }}</span>
                                            <!--
                                                Badge, not a tint on the name: the
                                                other holders of the instance
                                                privilege are exactly who this
                                                screen is about, and color.md §3.3
                                                keeps status legible without
                                                relying on colour.
                                            -->
                                            <Badge v-if="user.is_superadmin" status="warning">
                                                {{ t('admin.users.superadmin') }}
                                            </Badge>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-muted">{{ user.email }}</td>
                                    <td class="px-3 py-2 text-muted">
                                        <span v-if="user.organizations.length > 0">{{ user.organizations.join(', ') }}</span>
                                        <span v-else>{{ t('admin.users.noOrganizations') }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-muted">{{ formatDate(user.created_at) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <Button
                                            data-test="admin-user-impersonate"
                                            variant="ghost"
                                            :loading="busyId === user.id"
                                            @click="impersonate(user)"
                                        >
                                            {{ t('admin.users.impersonate') }}
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="hasMore">
                        <Button
                            data-test="admin-users-more"
                            variant="secondary"
                            :loading="loading"
                            @click="load(page + 1, { append: true })"
                        >
                            {{ t('admin.users.more') }}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
