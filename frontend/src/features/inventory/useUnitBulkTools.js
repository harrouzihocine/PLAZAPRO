import { computed, ref } from 'vue'
import { alertMessage, confirmAction, toastSuccess } from '@/composables/useConfirm'
import { t } from '@/i18n'
import { unitsApi } from '@/features/inventory/api'
import { todayInput } from '@/utils/format'

// The units-table bulk tools shared by the global browse (UnitsView) and a
// project's Units tab (LocationDetailView): row multi-select → cancel, Excel
// export of the current view, the empty import template, and the Excel
// re-import (fast bulk edit). `exportParams` returns the filter params
// describing what the table shows.
export function useUnitBulkTools(units, exportParams = () => ({})) {
  const selected = ref([])
  const exporting = ref(false)
  const downloadingTemplate = ref(false)
  const importInput = ref(null) // the hidden <input type=file>

  const selectedCount = computed(() => selected.value.length)

  async function cancelSelected() {
    const ids = selected.value.map((u) => u.id)
    if (!ids.length) return
    const ok = await confirmAction({
      title: t('inventory.bulkCancelTitle', ids.length),
      text: t('inventory.bulkCancelText'),
      confirmText: t('inventory.cancelUnit'),
      danger: true,
    })
    if (!ok) return

    try {
      const { cancelled, skipped } = await units.bulkCancel(ids)
      selected.value = []
      if (skipped.length) {
        // Held/sold units never bulk-cancel — say which ones stayed.
        await alertMessage({
          icon: 'warning',
          title: t('inventory.bulkCancelledCount', cancelled),
          text: t('inventory.bulkCancelSkipped', {
            count: skipped.length,
            refs: skipped.map((s) => s.reference ?? `#${s.id}`).join(', '),
          }),
        })
      } else {
        toastSuccess(t('inventory.bulkCancelledCount', cancelled))
      }
    } catch {
      /* surfaced via units.error */
    }
  }

  function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)
  }

  async function exportExcel() {
    exporting.value = true
    try {
      saveBlob(await unitsApi.exportExcel(exportParams()), `units-${todayInput()}.xlsx`)
    } finally {
      exporting.value = false
    }
  }

  async function downloadTemplate() {
    downloadingTemplate.value = true
    try {
      saveBlob(await unitsApi.downloadTemplate(), 'units-import-template.xlsx')
    } finally {
      downloadingTemplate.value = false
    }
  }

  function pickImportFile() {
    importInput.value?.click()
  }

  async function onImportFile(event) {
    const file = event.target.files?.[0]
    event.target.value = '' // same file re-selectable after a fix
    if (!file) return

    try {
      const { created, updated, errors } = await units.importFile(file)
      const summary = t('inventory.importSummary', { created, updated })
      if (errors.length) {
        const lines = errors
          .slice(0, 8)
          .map((e) => t('inventory.importErrorLine', { line: e.line, message: e.message }))
          .join('\n')
        await alertMessage({
          icon: created + updated ? 'warning' : 'error',
          title: t('inventory.importErrorsTitle', errors.length),
          text: `${summary}\n${lines}${errors.length > 8 ? '\n…' : ''}`,
        })
      } else {
        toastSuccess(summary)
      }
    } catch {
      /* surfaced via units.error */
    }
  }

  return {
    selected,
    selectedCount,
    exporting,
    downloadingTemplate,
    importInput,
    cancelSelected,
    exportExcel,
    downloadTemplate,
    pickImportFile,
    onImportFile,
  }
}
