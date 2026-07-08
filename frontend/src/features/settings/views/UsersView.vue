<script setup>
import { computed, onMounted, ref } from 'vue'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import BaseSelect from '@/components/base/BaseSelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import UserFormModal from '@/features/settings/components/UserFormModal.vue'
import TransferWorkModal from '@/features/settings/components/TransferWorkModal.vue'
import { usersApi } from '@/features/settings/api'
import { useUsersStore } from '@/features/settings/usersStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction, toastSuccess } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { countActiveFilters, initials } from '@/utils/format'
import { t } from '@/i18n'

const store = useUsersStore()
const activeFilterCount = computed(() => countActiveFilters(store.filters))
const auth = useAuthStore()

const modalOpen = ref(false)
const modalUser = ref(null) // null = creating

onMounted(() => store.fetch())
useRefreshable(() => store.fetch()) // pull-to-refresh + reconnect self-heal

function openCreate() {
  modalUser.value = null
  modalOpen.value = true
}

function openEdit(user) {
  modalUser.value = user
  modalOpen.value = true
}

async function onSave(payload) {
  try {
    if (modalUser.value) await store.update(modalUser.value.id, payload)
    else await store.create(payload)
    modalOpen.value = false
  } catch {
    /* error surfaced via store.error toast */
  }
}

async function toggleActive(user) {
  // Deactivating someone who still owns open work would silently orphan it —
  // warn and point at "Transfer work" first (workload needs users.transfer).
  if (user.is_active && auth.can('users.transfer')) {
    let openTotal = null // null = the peek itself failed
    try {
      openTotal = (await usersApi.workload(user.id, { totals: 1 })).open_total
    } catch {
      /* handled below — the safety net must not silently vanish */
    }
    if (openTotal === null || openTotal > 0) {
      const ok = await confirmAction({
        title:
          openTotal === null
            ? t('users.checkWorkFailed', { name: user.name })
            : t('users.stillHasOpen', { name: user.name, n: openTotal }),
        text: t('users.orphanWarning'),
        confirmText: t('users.deactivateAnyway'),
        danger: true,
      })
      if (!ok) return
    }
  }
  store.setActive(user.id, !user.is_active)
}

// Offboarding: the transfer wizard for one (leaving) user.
const transferUser = ref(null) // null = closed

async function onTransfer(payload) {
  const leaver = transferUser.value
  const after = payload.deactivate ? ' ' + t('users.deactivatedAfter') : ''
  if (
    !(await confirmAction({
      title: t('users.transferTitle', { leaver: leaver.name, successor: payload.successor_name }),
      text: t('users.transferText', { n: payload.open_total, successor: payload.successor_name, leaver: leaver.name }) + after,
      confirmText: t('users.transfer'),
      danger: true,
    }))
  ) {
    return
  }

  try {
    await store.transferWork(leaver.id, {
      successor_id: payload.successor_id,
      dispatch_to_pool: payload.dispatch_to_pool,
    })
  } catch {
    return // transfer failed — error surfaced via store.error toast
  }

  // The transfer is committed from here on: a failed deactivation must not
  // read as a failed hand-over (it surfaces its own toast via the store).
  transferUser.value = null
  toastSuccess(t('users.transferDone', { leaver: leaver.name, successor: payload.successor_name }))
  if (payload.deactivate && leaver.is_active) {
    await store.setActive(leaver.id, false).catch(() => {})
  }
}

// Clear a brute-force login lock (too many failed passwords) so the user can
// sign in again.
async function unlockUser(user) {
  if (
    await confirmAction({
      title: t('users.unlockTitle', { name: user.name }),
      text: t('users.unlockText'),
      confirmText: t('users.unlock'),
    })
  ) {
    store.unlock(user.id)
  }
}

async function cancelUser(user) {
  if (
    await confirmAction({
      title: t('users.cancelTitle', { name: user.name }),
      text: t('project.removeText'),
      confirmText: t('users.cancelUser'),
      danger: true,
    })
  ) {
    store.cancel(user.id)
  }
}
</script>

<template>
  <div>
    <PageHeader :title="$t('settings.users')" :subtitle="$t('users.subtitle')">
      <template #actions>
        <Button :label="$t('users.newUser')" icon="pi pi-plus" class="native-fab" @click="openCreate" />
      </template>
    </PageHeader>

    <SectionCard flush>
      <!-- Filters -->
      <FilterPanel :active-count="activeFilterCount">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 sm:px-5">
        <BaseSelect
          v-model="store.filters.role_id"
:aria-label="$t('users.filterByRole')"
          :placeholder="$t('users.allRoles')"
          class="w-full sm:w-44"
          :options="store.roles.map((r) => ({ value: r.id, label: r.name }))"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.department_id"
:aria-label="$t('users.filterByDepartment')"
          :placeholder="$t('users.allDepartments')"
          class="w-full sm:w-48"
          :options="store.departments.map((d) => ({ value: d.id, label: d.name }))"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.is_active"
:aria-label="$t('users.filterByState')"
          :placeholder="$t('users.anyState')"
          class="w-full sm:w-40"
          :options="[
            { value: '1', label: $t('status.active') },
            { value: '0', label: $t('status.inactive') },
          ]"
          @change="store.fetch()"
        />
      </div>
      </FilterPanel>

      <EmptyState v-if="!store.items.length" icon="pi pi-users" :title="$t('users.emptyTitle')" />

      <ul v-else class="divide-y divide-line">
        <li
          v-for="user in store.items"
          :key="user.id"
          class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-5"
        >
          <div class="flex min-w-0 flex-1 items-center gap-3">
            <Avatar
              :image="user.avatar_url || undefined"
              :label="user.avatar_url ? undefined : initials(user.name)"
              shape="circle"
              class="shrink-0 !bg-highlight !text-primary-700 dark:!text-primary-300"
              :class="{ 'opacity-50': !user.is_active }"
            />
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2">
                <span class="truncate text-sm font-medium text-ink">{{ user.name }}</span>
                <Tag v-if="!user.is_active" :value="$t('status.inactive').toLowerCase()" severity="secondary" />
                <Tag
                  v-if="user.locked_at"
                  v-tooltip.top="$t('users.lockedTooltip')"
                  :value="$t('users.locked')"
                  severity="danger"
                  icon="pi pi-lock"
                />
                <Tag v-if="user.role?.is_agent" :value="$t('users.agent')" severity="info" />
              </p>
              <p class="truncate text-xs text-mute">
                <span v-if="user.username" class="font-medium">@{{ user.username }}</span>
                <template v-if="user.username"> · </template>{{ user.email }} ·
                {{ user.role?.name ?? '—' }}
                <template v-if="user.department"> · {{ user.department.name }}</template>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-1">
            <Button
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              severity="secondary"
:aria-label="$t('users.editUser')"
              @click="openEdit(user)"
            />
            <Button
              v-if="user.locked_at && auth.can('users.unlock')"
              icon="pi pi-lock-open"
:label="$t('users.unlock')"
              text
              size="small"
              severity="warn"
              @click="unlockUser(user)"
            />
            <Button
              v-if="auth.can('users.transfer')"
              v-tooltip.top="$t('users.transferTooltip')"
              icon="pi pi-arrow-right-arrow-left"
              text
              rounded
              size="small"
              severity="secondary"
:aria-label="$t('users.transferWork')"
              @click="transferUser = user"
            />
            <Button
              :icon="user.is_active ? 'pi pi-pause' : 'pi pi-play'"
              :label="user.is_active ? $t('users.deactivate') : $t('users.activate')"
              text
              size="small"
              severity="secondary"
              @click="toggleActive(user)"
            />
            <Button
              icon="pi pi-ban"
              text
              rounded
              size="small"
              severity="danger"
:aria-label="$t('users.cancelUser')"
              @click="cancelUser(user)"
            />
          </div>
        </li>
      </ul>
    </SectionCard>

    <UserFormModal
      v-if="modalOpen"
      :user="modalUser"
      :roles="store.roles"
      :departments="store.departments"
      :saving="store.saving"
      @save="onSave"
      @close="modalOpen = false"
    />

    <TransferWorkModal
      v-if="transferUser"
      :user="transferUser"
      :users="store.items"
      :saving="store.saving"
      @transfer="onTransfer"
      @close="transferUser = null"
    />
  </div>
</template>
