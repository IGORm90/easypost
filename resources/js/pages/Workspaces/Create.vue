<script setup lang="ts">
import { ref } from 'vue';
import { isAxiosError } from 'axios';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import AppLayout from '../../layouts/AppLayout.vue';
import Card from '../../components/ui/Card.vue';
import Input from '../../components/ui/Input.vue';
import Button from '../../components/ui/Button.vue';
import { useOrganizationStore } from '../../stores/organization';

const { t } = useI18n();
const router = useRouter();
const organization = useOrganizationStore();
const name = ref('');
const submitting = ref(false);
const created = ref(false);
const nameError = ref('');
const error = ref('');

async function navigateTo(name: 'home' | 'workspaces'): Promise<void> {
    error.value = '';

    try {
        const failure = await router.replace({ name });
        if (failure) {
            error.value = t(created.value ? 'organization.create.navigationError' : 'organization.create.error');
        }
    } catch {
        error.value = t(created.value ? 'organization.create.navigationError' : 'organization.create.error');
    }
}

async function submit(): Promise<void> {
    if (submitting.value || created.value) {
        return;
    }

    nameError.value = '';
    error.value = '';
    const trimmedName = name.value.trim();

    if (!trimmedName) {
        nameError.value = t('organization.create.required');
        return;
    }

    if (Array.from(trimmedName).length > 255) {
        nameError.value = t('organization.create.tooLong');
        return;
    }

    submitting.value = true;

    try {
        await organization.create(trimmedName);
        // Creation and navigation have separate outcomes. Once the server
        // has saved the workspace, a failed navigation must offer only a
        // navigation retry, never a second POST creating another workspace.
        created.value = true;
        await navigateTo('home');
    } catch (caught: unknown) {
        if (isAxiosError<{ errors?: { name?: string[] } }>(caught)) {
            const fieldError = caught.response?.data?.errors?.name?.[0];
            if (caught.response?.status === 422 && fieldError) {
                nameError.value = fieldError;
            } else {
                error.value = t(caught.response ? 'organization.create.error' : 'organization.create.networkError');
            }
        } else {
            error.value = t('organization.create.error');
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex max-w-lg flex-col gap-4">
            <h1 class="text-2xl font-semibold tracking-tight text-text">{{ t('organization.create.title') }}</h1>
            <Card>
                <div class="flex flex-col gap-4">
                    <p class="text-sm text-muted">{{ t('organization.create.description') }}</p>
                    <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>

                    <Button v-if="created" :loading="submitting" @click="navigateTo('home')">
                        {{ t('organization.create.openCalendar') }}
                    </Button>
                    <form v-else novalidate class="flex flex-col gap-4" @submit.prevent="submit">
                        <Input
                            id="workspace-name"
                            v-model="name"
                            :label="t('organization.create.nameLabel')"
                            :placeholder="t('organization.create.namePlaceholder')"
                            :error="nameError || undefined"
                            :disabled="submitting"
                            required
                            autocomplete="organization"
                            autofocus
                        />
                        <div class="flex flex-wrap gap-2">
                            <Button type="submit" :loading="submitting">{{ t('organization.create.submit') }}</Button>
                            <Button variant="secondary" :disabled="submitting" @click="navigateTo('workspaces')">
                                {{ t('organization.create.cancel') }}
                            </Button>
                        </div>
                    </form>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
