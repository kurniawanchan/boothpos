<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useDebouncedFn } from '../../composables/useDebouncedFn';

/**
 * 041-back-office-ui-redesign (US1) — pencarian global (Ctrl/Cmd+K). Komponen ini hanya UI: pemanggil
 * menyuntikkan `search(q)` yang mengembalikan `{ groups: [{ type, label, items: [{ id, title, subtitle?, to }] }] }`.
 * TANPA fungsi itu komponen tidak dirender sama sekali — tidak ada kendali tak berfungsi di topbar (FR-006);
 * US4 menghubungkannya ke GET /search, yang menyaring hasil dengan izin dan outlet pemanggil di SERVER.
 *
 * Balasan yang datang terlambat (permintaan lama selesai setelah yang baru) dibuang lewat penomoran
 * permintaan, supaya hasil untuk "meja 4" tak pernah menimpa hasil untuk "meja 41".
 */
const props = defineProps({
  search: { type: Function, default: null },
  minLength: { type: Number, default: 2 },
  debounceMs: { type: Number, default: 250 },
});

const { t } = useI18n();
const router = useRouter();

const open = ref(false);
const query = ref('');
const loading = ref(false);
const failed = ref(false);
const groups = ref([]);
const inputRef = ref(null);
let seq = 0;

const run = useDebouncedFn(async (q) => {
  const mine = ++seq;
  loading.value = true;
  failed.value = false;
  try {
    const res = await props.search(q);
    if (mine !== seq) return;
    groups.value = res?.groups ?? [];
  } catch {
    if (mine !== seq) return;
    groups.value = [];
    failed.value = true;
  } finally {
    if (mine === seq) loading.value = false;
  }
}, props.debounceMs);

watch(query, (q) => {
  const trimmed = q.trim();
  if (trimmed.length < props.minLength) {
    seq++; // batalkan permintaan yang masih berjalan
    groups.value = [];
    loading.value = false;
    failed.value = false;
    return;
  }
  run(trimmed);
});

async function show() {
  open.value = true;
  await nextTick();
  inputRef.value?.focus();
}
function hide() {
  open.value = false;
  query.value = '';
  groups.value = [];
}
function go(item) {
  hide();
  router.push(item.to);
}
function onGlobalKey(e) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    if (props.search) (open.value ? hide() : show());
  } else if (e.key === 'Escape' && open.value) {
    hide();
  }
}
onMounted(() => document.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onGlobalKey));

const hasResults = () => groups.value.some((g) => g.items?.length);
</script>

<template>
  <div v-if="search">
    <button
      type="button"
      class="flex w-[260px] items-center gap-2.5 border-2 border-line bg-white px-3 py-2 text-left text-[13px] text-muted transition-colors hover:border-brand focus-visible:outline-none focus-visible:border-brand"
      :aria-label="t('frame.search_label')"
      aria-haspopup="dialog"
      @click="show"
    >
      <i class="ph-duotone ph-magnifying-glass text-[16px]" aria-hidden="true"></i>
      <span class="min-w-0 flex-1 truncate">{{ t('frame.search_placeholder') }}</span>
      <kbd class="border-2 border-line px-1.5 text-[10.5px] font-bold text-muted-4" aria-hidden="true">Ctrl K</kbd>
    </button>

    <Teleport to="body">
      <div v-if="open" class="fixed inset-0 z-[95] flex items-start justify-center bg-ink/40 px-4 pt-[12vh]" @mousedown.self="hide">
        <div role="dialog" aria-modal="true" :aria-label="t('frame.search_label')" class="flex max-h-[70vh] w-full max-w-[560px] flex-col border-2 border-line bg-white shadow-drawer">
          <div class="flex items-center gap-2.5 border-b-2 border-line px-4 py-3">
            <i class="ph-duotone ph-magnifying-glass text-[18px] text-muted" aria-hidden="true"></i>
            <input
              ref="inputRef"
              v-model="query"
              type="search"
              autocomplete="off"
              class="min-w-0 flex-1 bg-transparent text-[14px] outline-none"
              :placeholder="t('frame.search_placeholder')"
              :aria-label="t('frame.search_label')"
            />
          </div>
          <div class="min-h-[64px] flex-1 overflow-auto" aria-live="polite">
            <p v-if="query.trim().length < minLength" class="px-4 py-5 text-[12.5px] text-muted">{{ t('frame.search_minimum') }}</p>
            <p v-else-if="loading" class="px-4 py-5 text-[12.5px] text-muted">{{ t('frame.search_loading') }}</p>
            <p v-else-if="failed" class="px-4 py-5 text-[12.5px] text-danger-text">{{ t('page.load_failed') }}</p>
            <p v-else-if="!hasResults()" class="px-4 py-5 text-[12.5px] text-muted">{{ t('frame.search_empty', { q: query.trim() }) }}</p>
            <template v-else>
              <section v-for="g in groups" :key="g.type" :aria-label="g.label">
                <h3 v-if="g.items?.length" class="bg-surface px-4 py-1.5 text-[11px] font-bold uppercase tracking-wide text-muted">{{ g.label }}</h3>
                <ul>
                  <li v-for="item in g.items" :key="`${g.type}-${item.id}`">
                    <button type="button" class="flex w-full flex-col px-4 py-2.5 text-left hover:bg-mint-50 focus-visible:bg-mint-50 focus-visible:outline-none" @click="go(item)">
                      <span class="text-[13px] font-bold">{{ item.title }}</span>
                      <span v-if="item.subtitle" class="text-[11.5px] text-muted">{{ item.subtitle }}</span>
                    </button>
                  </li>
                </ul>
              </section>
            </template>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
