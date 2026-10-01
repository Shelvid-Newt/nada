<template>
  <v-dialog
    :model-value="modelValue"
    max-width="580"
    :persistent="submitting"
    scrollable
    @update:model-value="onDialogToggle"
  >
    <v-card rounded="lg" class="duplicate-study-dialog">
      <v-card-title class="d-flex align-center ga-2 pt-4">
        <v-icon color="primary">mdi-content-copy</v-icon>
        <span>{{ t('duplicate_dialog_title', 'Duplicate study') }}</span>
      </v-card-title>

      <!-- loading the suggested values -->
      <v-card-text v-if="phase === 'loading'" class="text-center py-10">
        <v-progress-circular indeterminate color="primary" size="40" />
      </v-card-text>

      <!-- cannot be shown / not supported -->
      <v-card-text v-else-if="phase === 'unavailable'">
        <v-alert type="warning" variant="tonal" density="compact">
          {{ loadError || t('duplicate_unsupported', 'Duplication is not available for this type of study yet.') }}
        </v-alert>
      </v-card-text>

      <!-- success: the natural next step is to edit what differs -->
      <v-card-text v-else-if="phase === 'success'" class="text-center py-8">
        <v-icon color="success" size="56" class="duplicate-success-icon">mdi-check-circle-outline</v-icon>
        <div class="text-h6 duplicate-success-title">{{ t('duplicate_success_title', 'Copy created') }}</div>
        <div class="text-body-2 text-medium-emphasis" role="status" aria-live="polite">
          {{ t('duplicate_success_message', '"%s" was created as a draft.', form.title) }}
        </div>
      </v-card-text>

      <!-- form -->
      <v-card-text v-else class="pb-0">
        <v-form id="duplicate-study-form" @submit.prevent="submit">
          <p class="text-body-2 duplicate-intro">
            {{ t('duplicate_dialog_intro', 'Create a draft copy of "%s", then change only what differs.', study?.title) }}
          </p>

          <v-text-field
            v-model="form.title"
            :label="t('duplicate_title_label', 'Title of the copy')"
            variant="outlined"
            density="comfortable"
            :disabled="submitting"
            autofocus
            class="duplicate-field"
            @focus="selectOnFirstFocus"
          />

          <v-text-field
            v-model="form.idno"
            :label="t('duplicate_idno_label', 'IDNO of the copy')"
            variant="outlined"
            density="comfortable"
            :disabled="submitting"
            :loading="idnoState === 'checking'"
            :error-messages="idnoError"
            :messages="idnoMessage"
            :append-inner-icon="idnoIcon"
            class="duplicate-field"
            spellcheck="false"
            autocomplete="off"
          />

          <fieldset v-if="showScope" class="duplicate-scope" :disabled="submitting">
            <legend class="text-subtitle-2 duplicate-scope__legend">{{ t('duplicate_scope_label', 'What should be copied?') }}</legend>
            <v-radio-group v-model="form.scope" hide-details density="compact">
              <v-radio value="full" :disabled="!dataDescription.available" class="duplicate-scope__option">
                <template #label>
                  <div class="py-1">
                    <div class="text-body-2 font-weight-medium">
                      {{ t('duplicate_scope_full_title', 'Study description and data description') }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      <template v-if="dataDescription.available">
                        {{ t('duplicate_scope_full_desc',
                          'Also copies the %s data file(s) and %s variable(s), with their labels and categories.',
                          dataDescription.data_files, dataDescription.variables) }}
                      </template>
                      <template v-else>
                        {{ t('duplicate_scope_full_unavailable', 'This study has no data files or variables to copy.') }}
                      </template>
                    </div>
                  </div>
                </template>
              </v-radio>
              <v-radio value="metadata" class="duplicate-scope__option">
                <template #label>
                  <div class="py-1">
                    <div class="text-body-2 font-weight-medium">
                      {{ t('duplicate_scope_metadata_title', 'Study description only') }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ t('duplicate_scope_metadata_desc', 'Metadata, tags and access type. The quickest option.') }}
                    </div>
                  </div>
                </template>
              </v-radio>
            </v-radio-group>
          </fieldset>

          <v-alert type="info" variant="tonal" density="compact" icon="mdi-pencil-ruler" role="note" class="duplicate-block">
            {{ t('duplicate_draft_notice',
              'The copy is created as a draft in the same collection. It stays hidden from the public until you publish it.') }}
          </v-alert>
          <p class="text-caption text-medium-emphasis duplicate-block">
            {{ t('duplicate_not_copied',
              'Not copied: DOI and alternate IDNOs, thumbnail, resources and files, citations and notes.') }}
          </p>

          <v-alert v-if="submitError" type="error" variant="tonal" density="compact" class="duplicate-block" role="alert">
            {{ submitError }}
          </v-alert>

          <div v-if="submitting" class="d-flex align-center ga-3 duplicate-block text-body-2 text-medium-emphasis" role="status" aria-live="polite">
            <v-progress-circular indeterminate size="20" width="2" color="primary" />
            <span>{{ t('duplicate_creating', 'Creating the copy... Large studies can take a moment.') }}</span>
          </div>
        </v-form>
      </v-card-text>

      <v-card-actions class="px-6 pb-4 pt-3">
        <v-spacer />
        <template v-if="phase === 'success'">
          <v-btn variant="text" @click="finish">{{ t('duplicate_back_to_list', 'Back to the list') }}</v-btn>
          <v-btn color="primary" variant="flat" :href="editUrl">
            {{ t('duplicate_edit_copy', 'Edit the copy') }}
          </v-btn>
        </template>
        <template v-else>
          <v-btn variant="text" :disabled="submitting" @click="close">{{ t('cancel', 'Cancel') }}</v-btn>
          <v-btn
            v-if="phase === 'form'"
            color="primary"
            variant="flat"
            type="submit"
            form="duplicate-study-form"
            :disabled="!canSubmit"
            :loading="submitting"
            prepend-icon="mdi-content-copy"
          >
            {{ t('duplicate_create', 'Create copy') }}
          </v-btn>
        </template>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useAppConfig } from '@/shared/composables/useAppConfig';
import { useCatalogApi } from '../composables/useCatalogApi';
import { useI18n } from '@/shared/composables/useI18n';

defineOptions({ name: 'DuplicateStudyDialog' });

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  /** Row of the study to duplicate: { id, title, idno, type }. */
  study: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'created']);

const { t } = useI18n();
const { siteUrl } = useAppConfig();
const { fetchDuplicateInfo, duplicateStudy, checkIdnoAvailability } = useCatalogApi();

const MAX_IDNO_LENGTH = 200;
const IDNO_CHECK_DELAY_MS = 400;

/** 'loading' | 'form' | 'unavailable' | 'success' */
const phase = ref('loading');
const loadError = ref('');
const submitting = ref(false);
const submitError = ref('');
const created = ref(null);

const info = ref(null);
const form = reactive({ title: '', idno: '', scope: 'metadata' });

/** 'idle' | 'checking' | 'available' | 'taken' | 'unknown' */
const idnoState = ref('idle');
let idnoTimer = null;
let idnoAbort = null;
let loadToken = 0;

const dataDescription = computed(() => info.value?.data_description ?? { available: false, data_files: 0, variables: 0 });
/** The data description only exists for microdata studies. */
const showScope = computed(() => info.value?.type === 'survey');

const idnoTrimmed = computed(() => form.idno.trim());

const idnoError = computed(() => {
  if (phase.value !== 'form') return [];
  if (!idnoTrimmed.value) return [t('duplicate_idno_required', 'The IDNO is required.')];
  if (idnoTrimmed.value.length > MAX_IDNO_LENGTH) {
    return [t('duplicate_idno_too_long', 'The IDNO is too long (200 characters maximum).')];
  }
  if (idnoState.value === 'taken') {
    return [t('duplicate_idno_taken', 'This IDNO is already used by another study.')];
  }
  return [];
});

const idnoMessage = computed(() => {
  if (idnoError.value.length) return [];
  if (idnoState.value === 'checking') return [t('duplicate_idno_checking', 'Checking availability...')];
  if (idnoState.value === 'available') return [t('duplicate_idno_available', 'This IDNO is available.')];
  return [t('duplicate_idno_hint', 'Must be unique in the catalog.')];
});

const idnoIcon = computed(() => {
  if (idnoError.value.length) return 'mdi-alert-circle-outline';
  if (idnoState.value === 'available') return 'mdi-check-circle-outline';
  return undefined;
});

const canSubmit = computed(
  () =>
    phase.value === 'form' &&
    !submitting.value &&
    form.title.trim() !== '' &&
    idnoError.value.length === 0 &&
    idnoState.value !== 'checking'
);

const editUrl = computed(() => `${siteUrl.value}/admin/catalog/edit/${created.value?.sid ?? ''}`);

function cancelIdnoCheck() {
  clearTimeout(idnoTimer);
  idnoTimer = null;
  if (idnoAbort) {
    idnoAbort.abort();
    idnoAbort = null;
  }
}

async function load() {
  const token = ++loadToken;
  cancelIdnoCheck();
  phase.value = 'loading';
  loadError.value = '';
  submitError.value = '';
  submitting.value = false;
  created.value = null;
  info.value = null;

  if (!props.study?.id) {
    phase.value = 'unavailable';
    return;
  }

  try {
    const result = await fetchDuplicateInfo(props.study.id);
    if (token !== loadToken) return;
    if (!result.supported) {
      phase.value = 'unavailable';
      return;
    }
    info.value = result;
    form.title = result.suggested_title || '';
    form.idno = result.suggested_idno || '';
    // the server only suggests an IDNO that is free
    idnoState.value = 'available';
    form.scope = result.type === 'survey' && result.data_description?.available ? 'full' : 'metadata';
    phase.value = 'form';
  } catch (e) {
    if (token !== loadToken) return;
    loadError.value =
      e?.response?.status === 403
        ? t('duplicate_error_denied', 'You do not have permission to duplicate this study.')
        : t('duplicate_error_generic', 'The copy could not be created.');
    phase.value = 'unavailable';
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) load();
    else cancelIdnoCheck();
  },
  { immediate: true }
);

// live "is this IDNO free?" feedback while typing
watch(
  () => form.idno,
  (value) => {
    if (phase.value !== 'form') return;
    cancelIdnoCheck();
    const idno = value.trim();
    if (!idno || idno.length > MAX_IDNO_LENGTH) {
      idnoState.value = 'idle';
      return;
    }
    if (idno === info.value?.suggested_idno) {
      idnoState.value = 'available';
      return;
    }
    idnoState.value = 'checking';
    idnoTimer = setTimeout(async () => {
      idnoAbort = new AbortController();
      const { signal } = idnoAbort;
      try {
        const state = await checkIdnoAvailability(idno, signal);
        if (idno === form.idno.trim()) idnoState.value = state;
      } catch {
        // cancelled by a newer keystroke
      }
    }, IDNO_CHECK_DELAY_MS);
  }
);

function selectOnFirstFocus(event) {
  event?.target?.select?.();
}

function errorMessageFor(error) {
  switch (error?.code) {
    case 'ACCESS_DENIED':
      return t('duplicate_error_denied', 'You do not have permission to duplicate this study.');
    case 'IDNO_TOO_LONG':
      return t('duplicate_idno_too_long', 'The IDNO is too long (200 characters maximum).');
    default: {
      const generic = t('duplicate_error_generic', 'The copy could not be created.');
      return error?.code ? `${generic} (${error.code})` : generic;
    }
  }
}

async function submit() {
  if (!canSubmit.value) return;
  submitError.value = '';
  submitting.value = true;
  try {
    const response = await duplicateStudy(props.study.id, {
      new_idno: idnoTrimmed.value,
      new_title: form.title.trim(),
      include_data_description: showScope.value && form.scope === 'full',
    });
    created.value = response;
    phase.value = 'success';
    emit('created', response);
  } catch (e) {
    if (e?.code === 'IDNO_ALREADY_EXISTS') {
      // the message under the IDNO field says it already; no second banner
      idnoState.value = 'taken';
    } else {
      submitError.value = errorMessageFor(e);
    }
  } finally {
    submitting.value = false;
  }
}

function close() {
  emit('update:modelValue', false);
}

/** "Back to the list": the list refreshes on `created`, so closing is enough. */
function finish() {
  close();
}

function onDialogToggle(open) {
  if (!open && submitting.value) return;
  emit('update:modelValue', open);
}

onBeforeUnmount(cancelIdnoCheck);
</script>

<style scoped>
/*
 * Spacing is set here rather than with Vuetify's mb-*, mt-* utilities: the admin base.css
 * resets margins outside any CSS layer, which wins over the layered Vuetify utilities.
 */
.duplicate-intro {
  margin-bottom: 20px;
}

.duplicate-field {
  margin-bottom: 4px;
}

.duplicate-block {
  margin-top: 12px;
}

.duplicate-scope {
  border: 0;
  padding: 0;
  margin: 16px 0 8px;
  min-width: 0;
}

.duplicate-scope__legend {
  padding: 0;
  margin-bottom: 4px;
}

.duplicate-scope__option {
  align-items: flex-start;
}

/* Vuetify's own icon spacing sits in a layer the admin reset overrides */
.duplicate-study-dialog :deep(.v-alert__prepend) {
  margin-inline-end: 12px;
}

.duplicate-study-dialog :deep(.v-btn__prepend) {
  margin-inline: -2px 8px;
}

.duplicate-success-icon {
  margin-bottom: 12px;
}

.duplicate-success-title {
  margin-bottom: 4px;
}
</style>
