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
    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
        <label for="organization-switcher" class="sr-only">{{ t('organization.switcher.label') }}</label>
        <select
            id="organization-switcher"
            :value="organization.current?.id"
            class="min-w-0 max-w-full truncate rounded-xl border border-border-strong bg-surface px-2 py-1 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary sm:max-w-xs"
            @change="onChange"
        >
            <option v-if="!auth.organizations.length" value="">{{ t('organization.switcher.empty') }}</option>
            <option v-for="org in auth.organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
        </select>
    </div>
</template>
