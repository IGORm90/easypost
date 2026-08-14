<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { useOrganizationStore } from '../stores/organization';

const { t } = useI18n();
const auth = useAuthStore();
const organization = useOrganizationStore();

function onChange(event: Event): void {
    const id = (event.target as HTMLSelectElement).value;

    if (id) {
        organization.switchTo(id);
    }
}
</script>

<template>
    <div class="flex items-center gap-2">
        <label for="organization-switcher" class="sr-only">{{ t('organization.switcher.label') }}</label>
        <select
            id="organization-switcher"
            :value="organization.current?.id"
            class="rounded-xl border border-border bg-surface px-2 py-1 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary"
            @change="onChange"
        >
            <option v-if="!auth.organizations.length" value="">{{ t('organization.switcher.empty') }}</option>
            <option v-for="org in auth.organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
        </select>
    </div>
</template>
