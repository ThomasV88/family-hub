<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import StyleSection from '../StyleSection.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiTextarea from '../../Components/ui/UiTextarea.vue';
import UiSelect from '../../Components/ui/UiSelect.vue';
import UiCheckbox from '../../Components/ui/UiCheckbox.vue';
import UiRadio from '../../Components/ui/UiRadio.vue';
import UiSwitch from '../../Components/ui/UiSwitch.vue';
import UiButton from '../../Components/ui/UiButton.vue';

const { t, tm } = useI18n();

// lang/*.json error.email_example contains a bare "@", which vue-i18n reads as a linked-message marker and refuses to compile.
// Fall back to the raw string until the key is escaped as {'@'} in the lang files (owned by another plan).
const emailError = (() => {
    try {
        return t('error.email_example');
    } catch {
        return String(tm('error.email_example'));
    }
})();

const states = [
    { key: 'default', cls: '', props: {}, value: '' },
    { key: 'hover', cls: 'is-hover', props: {}, value: '' },
    { key: 'focus', cls: 'is-focus', props: {}, value: '' },
    { key: 'filled', cls: '', props: {}, value: 'name@example.com' },
    { key: 'disabled', cls: '', props: { disabled: true }, value: 'name@example.com' },
    { key: 'readonly', cls: '', props: { readonly: true }, value: 'name@example.com' },
    { key: 'error', cls: '', props: {}, value: 'name@', error: true },
];
const selectDisabled = (s) => ({ disabled: s.props.disabled });
const options = [
    { value: 'groceries', label: t('style.demo.category_groceries') },
    { value: 'home', label: t('style.demo.category_home') },
    { value: 'doctor', label: t('style.demo.category_doctor') },
];

const checkA = ref(false);
const checkB = ref(true);
const radioDemo = ref('shared');
const switchOn = ref(true);
const switchOff = ref(false);

const form = ref({ email: '', category: 'groceries', notes: '', remind: false, visibility: 'shared' });
const formError = ref(false);
function onSubmit() {
    formError.value = !/^\S+@\S+\.\S+$/.test(form.value.email);
}
</script>

<template>
    <StyleSection id="forms" title-key="style.section.forms">
        <div class="forms__states">
            <div v-for="state in states" :key="state.key" class="forms__state">
                <h3 class="forms__state-title">{{ t(`style.state.${state.key}`) }}</h3>
                <UiField :label="t('style.demo.email')" :error="state.error ? emailError : ''">
                    <UiInput :class="state.cls" :model-value="state.value" v-bind="state.props" />
                </UiField>
                <UiField :label="t('style.demo.notes')" :error="state.error ? emailError : ''">
                    <UiTextarea :class="state.cls" :model-value="state.value" v-bind="state.props" />
                </UiField>
                <UiField :label="t('style.demo.category')" :error="state.error ? emailError : ''">
                    <UiSelect :class="state.cls" model-value="home" :options="options" v-bind="selectDisabled(state)" />
                </UiField>
            </div>
        </div>

        <div class="forms__choices">
            <div class="forms__group">
                <UiCheckbox v-model="checkA">{{ t('style.state.default') }}</UiCheckbox>
                <UiCheckbox v-model="checkB">{{ t('style.demo.remind') }}</UiCheckbox>
                <UiCheckbox :model-value="false" indeterminate>{{ t('style.state.filled') }}</UiCheckbox>
                <UiCheckbox :model-value="true" disabled>{{ t('style.state.disabled') }}</UiCheckbox>
            </div>
            <fieldset class="forms__group forms__fieldset">
                <legend class="forms__legend">{{ t('style.demo.visibility') }}</legend>
                <UiRadio v-model="radioDemo" value="shared" name="style-visibility">{{ t('style.demo.shared') }}</UiRadio>
                <UiRadio v-model="radioDemo" value="personal" name="style-visibility">{{ t('style.demo.personal') }}</UiRadio>
                <UiRadio :model-value="'x'" value="y" name="style-visibility-off" disabled>{{ t('style.state.disabled') }}</UiRadio>
            </fieldset>
            <div class="forms__group">
                <UiSwitch v-model="switchOn">{{ t('style.demo.notifications') }}</UiSwitch>
                <UiSwitch v-model="switchOff">{{ t('style.demo.notifications') }}</UiSwitch>
                <UiSwitch :model-value="true" disabled>{{ t('style.state.disabled') }}</UiSwitch>
            </div>
        </div>

        <form class="forms__demo" novalidate @submit.prevent="onSubmit">
            <UiField :label="t('style.demo.email')" :hint="t('style.demo.email_hint')" :error="formError ? emailError : ''">
                <UiInput v-model="form.email" type="email" autocomplete="off" />
            </UiField>
            <UiField :label="t('style.demo.category')">
                <UiSelect v-model="form.category" :options="options" />
            </UiField>
            <UiField :label="t('style.demo.notes')">
                <UiTextarea v-model="form.notes" />
            </UiField>
            <UiCheckbox v-model="form.remind">{{ t('style.demo.remind') }}</UiCheckbox>
            <fieldset class="forms__fieldset forms__demo-radios">
                <legend class="forms__legend">{{ t('style.demo.visibility') }}</legend>
                <UiRadio v-model="form.visibility" value="shared" name="demo-visibility">{{ t('style.demo.shared') }}</UiRadio>
                <UiRadio v-model="form.visibility" value="personal" name="demo-visibility">{{ t('style.demo.personal') }}</UiRadio>
            </fieldset>
            <UiButton variant="primary" type="submit">{{ t('ui.save_changes') }}</UiButton>
        </form>
    </StyleSection>
</template>

<style scoped>
.forms__states { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: var(--space-4); }
.forms__state-title { margin: 0 0 var(--space-2); font-size: var(--text-sm); font-weight: 600; color: var(--color-text-muted); }
.forms__choices { display: flex; flex-wrap: wrap; gap: var(--space-6); margin-top: var(--space-4); }
.forms__group { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); }
.forms__fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
.forms__legend { padding: 0; margin-bottom: var(--space-1); font-size: var(--text-sm); font-weight: 600; }
.forms__demo { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-3); max-width: 420px; margin-top: var(--space-6); padding-top: var(--space-4); border-top: 1px solid var(--color-border); }
.forms__demo :deep(.ui-field) { width: 100%; margin-bottom: 0; }
.forms__demo-radios { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); }
</style>
