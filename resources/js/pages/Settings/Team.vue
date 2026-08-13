<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isAxiosError } from 'axios';
import AppLayout from '../../layouts/AppLayout.vue';
import Card from '../../components/ui/Card.vue';
import Badge from '../../components/ui/Badge.vue';
import Button from '../../components/ui/Button.vue';
import Modal from '../../components/ui/Modal.vue';
import Input from '../../components/ui/Input.vue';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/auth';
import { useOrganizationStore } from '../../stores/organization';

type Role = 'SUPERADMIN' | 'ADMIN' | 'USER';

interface Member {
    id: string;
    name: string;
    email: string;
    role: Role;
    joined_at: string;
}

const { t } = useI18n();
const auth = useAuthStore();
const organization = useOrganizationStore();

const members = ref<Member[]>([]);
const loading = ref(false);

async function loadMembers(): Promise<void> {
    loading.value = true;

    try {
        const { data } = await api.get<Member[]>('/organizations/current/members');
        members.value = data;
    } finally {
        loading.value = false;
    }
}

onMounted(loadMembers);
// Re-fetch whenever the caller switches organizations, so this page never
// shows a previous tenant's roster under the new one — the "refreshes
// dependent data" half of switching, on the consumer side (the store
// itself only refreshes auth/me, see stores/organization.ts).
watch(() => organization.current?.id, (id, previousId) => {
    if (id && id !== previousId) {
        loadMembers();
    }
});

const myRole = computed<Role | null>(() => {
    const me = members.value.find((m) => m.id === auth.user?.id);

    return me?.role ?? null;
});

const canManage = computed(() => myRole.value === 'ADMIN' || myRole.value === 'SUPERADMIN');

function canRemove(member: Member): boolean {
    if (!canManage.value || member.id === auth.user?.id) {
        return false;
    }

    // Mirrors OrganizationPolicy::removeOrDemote() server-side: only a
    // SUPERADMIN may touch another SUPERADMIN's membership.
    return member.role !== 'SUPERADMIN' || myRole.value === 'SUPERADMIN';
}

function assignableRoles(member: Member): Role[] {
    if (!canRemove(member)) {
        return [];
    }

    return myRole.value === 'SUPERADMIN' ? ['USER', 'ADMIN', 'SUPERADMIN'] : ['USER', 'ADMIN'];
}

async function removeMember(member: Member): Promise<void> {
    if (!window.confirm(t('settings.team.removeConfirm'))) {
        return;
    }

    await api.delete(`/organizations/current/members/${member.id}`);
    members.value = members.value.filter((m) => m.id !== member.id);
}

async function changeRole(member: Member, role: Role): Promise<void> {
    if (role === member.role) {
        return;
    }

    await api.put(`/organizations/current/members/${member.id}/role`, { role });
    member.role = role;
}

const inviteOpen = ref(false);
const inviteForm = reactive({ email: '', role: 'USER' as Role });
const inviteUrl = ref<string | null>(null);
const inviteErrors = ref<Record<string, string[]>>({});
const inviting = ref(false);
const inviteCopied = ref(false);

const invitableRoles = computed<Role[]>(() => (myRole.value === 'SUPERADMIN' ? ['USER', 'ADMIN', 'SUPERADMIN'] : ['USER', 'ADMIN']));

function openInvite(): void {
    inviteForm.email = '';
    inviteForm.role = 'USER';
    inviteUrl.value = null;
    inviteErrors.value = {};
    inviteCopied.value = false;
    inviteOpen.value = true;
}

async function submitInvite(): Promise<void> {
    inviteErrors.value = {};
    inviting.value = true;

    try {
        const { data } = await api.post<{ url: string }>('/organizations/current/invites', {
            email: inviteForm.email || undefined,
            role: inviteForm.role,
        });
        inviteUrl.value = data.url;
    } catch (e) {
        if (isAxiosError(e) && e.response?.status === 422) {
            inviteErrors.value = e.response.data?.errors ?? {};
        }
    } finally {
        inviting.value = false;
    }
}

async function copyInviteUrl(): Promise<void> {
    if (!inviteUrl.value) {
        return;
    }

    await navigator.clipboard.writeText(inviteUrl.value);
    inviteCopied.value = true;
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex max-w-3xl flex-col gap-4">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold text-text">{{ t('settings.team.title') }}</h1>
                <!-- secondary, not primary: the modal's own submit button
                     below is this screen's one primary action (color.md
                     §3.1 — at most one primary element per screen, and
                     both this trigger and that submit button can be in
                     the DOM at the same time once the modal is open). -->
                <Button v-if="canManage" variant="secondary" @click="openInvite">
                    {{ t('settings.team.invite.title') }}
                </Button>
            </div>

            <Card>
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-surface-2 text-muted">
                        <tr>
                            <th class="px-3 py-2 font-medium">{{ t('settings.team.member') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('settings.team.role') }}</th>
                            <th v-if="canManage" class="px-3 py-2 font-medium">{{ t('settings.team.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="member in members" :key="member.id" class="border-b border-border last:border-0">
                            <td class="px-3 py-2">
                                <p class="text-text">{{ member.name }}</p>
                                <p class="text-xs text-muted">{{ member.email }}</p>
                            </td>
                            <td class="px-3 py-2">
                                <select
                                    v-if="assignableRoles(member).length"
                                    :value="member.role"
                                    class="rounded-xl border border-border bg-surface px-2 py-1 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                                    @change="changeRole(member, ($event.target as HTMLSelectElement).value as Role)"
                                >
                                    <option v-for="role in assignableRoles(member)" :key="role" :value="role">
                                        {{ t(`settings.team.roles.${role}`) }}
                                    </option>
                                </select>
                                <Badge v-else status="neutral">{{ t(`settings.team.roles.${member.role}`) }}</Badge>
                            </td>
                            <td v-if="canManage" class="px-3 py-2">
                                <Button v-if="canRemove(member)" variant="danger" @click="removeMember(member)">
                                    {{ t('settings.team.remove') }}
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>

        <Modal :open="inviteOpen" :title="t('settings.team.invite.title')" @close="inviteOpen = false">
            <div v-if="inviteUrl" class="flex flex-col gap-3">
                <p class="text-sm text-success" role="status">{{ t('settings.team.invite.success') }}</p>
                <code class="break-all rounded-xl bg-surface-2 p-2 text-xs text-text">{{ inviteUrl }}</code>
                <Button variant="secondary" @click="copyInviteUrl">
                    {{ inviteCopied ? t('ui.copied') : t('settings.team.invite.copy') }}
                </Button>
            </div>

            <form v-else class="flex flex-col gap-4" @submit.prevent="submitInvite">
                <Input
                    id="invite-email"
                    v-model="inviteForm.email"
                    type="email"
                    :label="t('settings.team.invite.email')"
                    :error="inviteErrors.email?.[0]"
                />

                <div class="flex flex-col gap-1">
                    <label for="invite-role" class="text-sm font-medium text-text">{{ t('settings.team.invite.role') }}</label>
                    <select
                        id="invite-role"
                        v-model="inviteForm.role"
                        class="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
                    >
                        <option v-for="role in invitableRoles" :key="role" :value="role">{{ t(`settings.team.roles.${role}`) }}</option>
                    </select>
                </div>

                <Button type="submit" variant="primary" :loading="inviting">{{ t('settings.team.invite.submit') }}</Button>
            </form>
        </Modal>
    </AppLayout>
</template>
