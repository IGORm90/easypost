import { config } from '@vue/test-utils';
import { i18n } from './i18n';

// The brief's spec files mount UI-kit components directly, without wiring
// app plugins themselves. Install i18n globally for every test so
// components using useI18n()/$t() work the same way they do inside the
// real app, without each spec having to repeat the boilerplate.
config.global.plugins = [i18n];
