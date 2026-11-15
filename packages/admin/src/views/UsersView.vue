<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import { Plus, ShieldOff, Trash2, UserCheck } from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import type { AdminRole, AdminUser } from "../lib/types";
import { formatDateTime } from "../lib/utils";
import { adminStore, notifyToast } from "../stores/admin";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";
import ModalDialog from "../components/ui/ModalDialog.vue";

const users = ref<AdminUser[]>([]);
const createOpen = ref(false);
const saving = ref(false);

const confirmOpen = ref(false);
const confirmAction = ref<{ type: "reset2fa" | "delete"; user: AdminUser } | null>(null);

const newUser = reactive({
  name: "",
  email: "",
  role: "editor" as AdminRole,
  password: "",
});

async function load() {
  try {
    const res = await apiGet<{ users: AdminUser[] }>("users.list");
    users.value = res.users || [];
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load users.", "destructive");
  }
}

async function createUser() {
  saving.value = true;
  try {
    await apiPost("users.create", newUser);
    newUser.name = "";
    newUser.email = "";
    newUser.role = "editor";
    newUser.password = "";
    createOpen.value = false;
    notifyToast("Operator account created.", "success");
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Create failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

async function changeRole(u: AdminUser, role: AdminRole) {
  try {
    await apiPost("users.update", { id: u.id, role });
    notifyToast(`Updated role for ${u.email} to ${role}.`, "success");
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Role update failed.", "destructive");
  }
}

async function toggleActive(u: AdminUser) {
  try {
    await apiPost("users.update", { id: u.id, is_active: !u.is_active });
    notifyToast(`${u.email} ${!u.is_active ? "activated" : "deactivated"}.`);
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Status update failed.", "destructive");
  }
}

function reset2fa(u: AdminUser) {
  confirmAction.value = { type: "reset2fa", user: u };
  confirmOpen.value = true;
}

function deleteUser(u: AdminUser) {
  confirmAction.value = { type: "delete", user: u };
  confirmOpen.value = true;
}

async function executeConfirmedAction() {
  if (!confirmAction.value) return;
  saving.value = true;
  const { type, user } = confirmAction.value;
  try {
    if (type === "reset2fa") {
      await apiPost("users.update", { id: user.id, reset_2fa: true });
      notifyToast(`2FA reset for ${user.email}.`, "success");
    } else {
      await apiPost("users.delete", { id: user.id });
      notifyToast(`Deleted ${user.email}.`, "success");
    }
    confirmOpen.value = false;
    confirmAction.value = null;
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Operation failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Users &amp; RBAC Access</h2>
        <p class="text-sm text-muted-foreground">Owner-managed operator directory with Argon2id passwords and TOTP 2FA.</p>
      </div>
      <Button size="sm" @click="createOpen = true">
        <Plus class="h-4 w-4" aria-hidden="true" />
        <span>Add Operator</span>
      </Button>
    </div>

    <Card class="overflow-hidden">
      <div tabindex="0" role="region" aria-labelledby="users-cap" class="lumora-table-wrap">
        <table class="w-full text-sm text-left">
          <caption id="users-cap" class="sr-only">Admin operators table</caption>
          <thead class="border-b border-border bg-muted/60 text-xs uppercase text-muted-foreground">
            <tr>
              <th class="px-4 py-2.5">Operator</th>
              <th class="px-4 py-2.5">Role</th>
              <th class="px-4 py-2.5">2FA</th>
              <th class="px-4 py-2.5">Status</th>
              <th class="px-4 py-2.5">Last Login</th>
              <th class="px-4 py-2.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="u in users" :key="u.id" class="hover:bg-muted/30">
              <td class="px-4 py-3">
                <div class="font-medium">{{ u.name }}</div>
                <div class="text-xs text-muted-foreground">{{ u.email }}</div>
              </td>
              <td class="px-4 py-3">
                <select
                  :aria-label="`Role for ${u.email}`"
                  :value="u.role"
                  class="h-8 rounded-md border border-input bg-background px-2 text-xs"
                  @change="changeRole(u, ($event.target as HTMLSelectElement).value as AdminRole)"
                >
                  <option value="owner">owner</option>
                  <option value="editor">editor</option>
                  <option value="viewer">viewer</option>
                </select>
              </td>
              <td class="px-4 py-3">
                <Badge :variant="u.totp_enabled ? 'success' : 'outline'">{{ u.totp_enabled ? "Enrolled" : "Off" }}</Badge>
              </td>
              <td class="px-4 py-3">
                <Badge :variant="u.is_active ? 'success' : 'destructive'">{{ u.is_active ? "Active" : "Disabled" }}</Badge>
              </td>
              <td class="px-4 py-3 text-xs text-muted-foreground whitespace-nowrap">{{ formatDateTime(u.last_login_at) }}</td>
              <td class="px-4 py-3 text-right space-x-1 whitespace-nowrap">
                <Button v-if="u.totp_enabled" variant="ghost" size="sm" title="Reset 2FA" @click="reset2fa(u)">
                  <ShieldOff class="h-3.5 w-3.5" aria-hidden="true" />
                </Button>
                <Button variant="ghost" size="sm" @click="toggleActive(u)">
                  <UserCheck class="h-3.5 w-3.5" aria-hidden="true" />
                  <span>{{ u.is_active ? "Disable" : "Enable" }}</span>
                </Button>
                <Button
                  v-if="u.id !== adminStore.user?.id"
                  variant="ghost"
                  size="sm"
                  aria-label="Delete user"
                  @click="deleteUser(u)"
                >
                  <Trash2 class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
                </Button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>

    <ModalDialog
      v-model:open="createOpen"
      title="Create Operator Account"
      description="Provision a new role-governed admin login with Argon2id credential hashing."
      size="sm"
    >
      <form id="create-operator-form" class="space-y-4" @submit.prevent="createUser">
        <div class="space-y-1">
          <label for="nu-name" class="text-xs font-medium">Name</label>
          <Input id="nu-name" v-model="newUser.name" required />
        </div>
        <div class="space-y-1">
          <label for="nu-email" class="text-xs font-medium">Email</label>
          <Input id="nu-email" v-model="newUser.email" type="email" required spellcheck="false" />
        </div>
        <div class="space-y-1">
          <label for="nu-role" class="text-xs font-medium">Role</label>
          <select id="nu-role" v-model="newUser.role" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
            <option value="viewer">viewer (Read-only)</option>
            <option value="editor">editor (Content + Inbox)</option>
            <option value="owner">owner (Full Security + Dispatch + Legal)</option>
          </select>
        </div>
        <div class="space-y-1">
          <label for="nu-pass" class="text-xs font-medium">Initial Password (min 10 chars)</label>
          <Input id="nu-pass" v-model="newUser.password" type="password" minlength="10" required autocomplete="new-password" />
        </div>
      </form>
      <template #footer>
        <Button variant="outline" size="sm" @click="createOpen = false">Cancel</Button>
        <Button type="submit" form="create-operator-form" size="sm" :disabled="saving">
          {{ saving ? "Creating..." : "Create Operator" }}
        </Button>
      </template>
    </ModalDialog>

    <ConfirmDialog
      v-model:open="confirmOpen"
      :title="confirmAction?.type === 'reset2fa' ? 'Reset Operator Two-Factor Auth?' : 'Delete Operator Account?'"
      :description="confirmAction?.type === 'reset2fa' ? `Reset 2FA for ${confirmAction?.user.email}? Their active sessions will be revoked immediately.` : `Permanently delete user ${confirmAction?.user.email}? This cannot be undone.`"
      :confirm-label="confirmAction?.type === 'reset2fa' ? 'Reset 2FA' : 'Delete Account'"
      variant="destructive"
      :loading="saving"
      @confirm="executeConfirmedAction"
    />
  </div>
</template>
