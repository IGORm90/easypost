<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { useOrganizationStore } from '../stores/organization';

/**
 * «Кобальт» moves the switcher into the dark sidebar (and the phone menu),
 * so it renders twice in one document: `inputId` keeps the label/select
 * pairing unique, and `tone="nav"` draws it on the navigation surface.
 */
withDefaults(defineProps<{ inputId?: string; tone?: 'default' | 'nav' }>(), { inputId: 'organization-switcher', tone: 'default' });

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
    <div class="flex min-w-0 flex-col">
        <label :for="inputId" class="sr-only">{{ t('organization.switcher.label') }}</label>
        <select
            :id="inputId"
            :value="organization.current?.id"
            class="w-full min-w-0 truncate rounded-xl border px-2.5 py-2 text-[13px] focus:outline-none focus:ring-2"
            :class="
                tone === 'nav'
                    ? 'border-nav-border bg-nav-active text-on-nav focus:ring-nav-muted'
                    : 'border-border-strong bg-surface text-text focus:ring-primary'
            "
            @change="onChange"
        >
            <option v-if="!auth.organizations.length" value="">{{ t('organization.switcher.empty') }}</option>
            <option v-for="org in auth.organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
        </select>
    </div>
</template>
