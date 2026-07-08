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
            ? `Could not check ${user.name}'s open work`
            : `${user.name} still has ${openTotal} open item${openTotal === 1 ? '' : 's'}`,
        text: 'Clients, projects, planned actions, visits or tasks would be left without an owner. Use "Transfer work" first, or deactivate anyway.',
        confirmText: 'Deactivate anyway',
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
  const after = payload.deactivate ? ' The account is deactivated right after.' : ''
  if (
    !(await confirmAction({
      title: `Hand ${leaver.name}'s open work to ${payload.successor_name}?`,
      text: `${payload.open_total} open item${payload.open_total === 1 ? '' : 's'} will move to ${payload.successor_name}. History (calls, conducted visits, closed deals) stays under ${leaver.name}'s name.${after}`,
      confirmText: 'Transfer',
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
  toastSuccess(`${leaver.name}'s open work was handed to ${payload.successor_name}.`)
  if (payload.deactivate && leaver.is_active) {
    await store.setActive(leaver.id, false).catch(() => {})
  }
}

// Clear a brute-force login lock (too many failed passwords) so the user can
// sign in again.
async function unlockUser(user) {
  if (
    await confirmAction({
      title: `Unlock "${user.name}"?`,
      text: 'The account was locked after too many failed sign-in attempts. Unlocking lets them try again.',
      confirmText: 'Unlock',
    })
  ) {
    store.unlock(user.id)
  }
}

async function cancelUser(user) {
  if (
    await confirmAction({
      title: `Cancel user "${user.name}"?`,
      text: 'The record is kept but marked cancelled.',
      confirmText: 'Cancel user',
      danger: true,
    })
  ) {
    store.cancel(user.id)
  }
}
</script>

<template>
  <div>
    <PageHeader title="Users" subtitle="People with access. Exactly one role each.">
      <template #actions>
        <Button label="New user" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <SectionCard flush>
      <!-- Filters -->
      <FilterPanel :active-count="activeFilterCount">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 sm:px-5">
        <BaseSelect
          v-model="store.filters.role_id"
          aria-label="Filter by role"
          placeholder="All roles"
          class="w-full sm:w-44"
          :options="store.roles.map((r) => ({ value: r.id, label: r.name }))"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.department_id"
          aria-label="Filter by department"
          placeholder="All departments"
          class="w-full sm:w-48"
          :options="store.departments.map((d) => ({ value: d.id, label: d.name }))"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.is_active"
          aria-label="Filter by state"
          placeholder="Any state"
          class="w-full sm:w-40"
          :options="[
            { value: '1', label: 'Active' },
            { value: '0', label: 'Inactive' },
          ]"
          @change="store.fetch()"
        />
      </div>
      </FilterPanel>

      <EmptyState v-if="!store.items.length" icon="pi pi-users" title="No users match" />

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
                <Tag v-if="!user.is_active" value="inactive" severity="secondary" />
                <Tag
                  v-if="user.locked_at"
                  v-tooltip.top="'Locked after too many failed sign-in attempts'"
                  value="locked"
                  severity="danger"
                  icon="pi pi-lock"
                />
                <Tag v-if="user.role?.is_agent" value="agent" severity="info" />
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
              aria-label="Edit user"
              @click="openEdit(user)"
            />
            <Button
              v-if="user.locked_at && auth.can('users.unlock')"
              icon="pi pi-lock-open"
              label="Unlock"
              text
              size="small"
              severity="warn"
              @click="unlockUser(user)"
            />
            <Button
              v-if="auth.can('users.transfer')"
              v-tooltip.top="'Transfer work — hand this user\'s open clients, projects and visits to a successor'"
              icon="pi pi-arrow-right-arrow-left"
              text
              rounded
              size="small"
              severity="secondary"
              aria-label="Transfer work"
              @click="transferUser = user"
            />
            <Button
              :icon="user.is_active ? 'pi pi-pause' : 'pi pi-play'"
              :label="user.is_active ? 'Deactivate' : 'Activate'"
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
              aria-label="Cancel user"
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
