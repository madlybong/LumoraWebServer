<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { Check, Copy, Download, KeyRound, ShieldAlert, ShieldCheck, ShieldOff, Trash2 } from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import { qrSvgPath } from "../lib/qr-svg";
import { copyText, formatDateTime } from "../lib/utils";
import { adminStore, notifyToast, refreshAuthStatus } from "../stores/admin";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";

const sessions = ref<Array<{ id: number; is_current: boolean; ip_hash_short: string; last_seen_at: string; expires_at: string }>>([]);
const displayName = ref(adminStore.user?.name || "");
const currentPassword = ref("");
const newPassword = ref("");
const totpSecret = ref("");
const totpUri = ref("");
const qrSvg = ref<{ viewBox: string; path: string } | null>(null);
const confirmCode = ref("");
const recoveryCodes = ref<string[]>([]);
const copiedCodes = ref(false);
const disablePassword = ref("");
const disableCode = ref("");
const busy = ref(false);

const revokeTargetId = ref<number | null>(null);
const revokeConfirmOpen = ref(false);
const revokeAllOthersConfirmOpen = ref(false);

const otherSessions = computed(() => sessions.value.filter((s) => !s.is_current));

async function load() {
  try {
    const res = await apiGet<{ sessions: typeof sessions.value }>("profile.get");
    sessions.value = res.sessions || [];
    displayName.value = adminStore.user?.name || displayName.value;
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Could not load profile.", "destructive");
  }
}

async function updateDisplayName() {
  busy.value = true;
  try {
    await apiPost("profile.update", { name: displayName.value });
    await refreshAuthStatus();
    notifyToast("Display name updated.", "success");
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Profile update failed.", "destructive");
  } finally {
    busy.value = false;
  }
}

async function changePassword() {
  busy.value = true;
  try {
    await apiPost("profile.password", {
      current_password: currentPassword.value,
      new_password: newPassword.value,
    });
    currentPassword.value = "";
    newPassword.value = "";
    notifyToast("Password updated and other sessions signed out.", "success");
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Password change failed.", "destructive");
  } finally {
    busy.value = false;
  }
}

async function beginTotp() {
  busy.value = true;
  try {
    const res = await apiPost<{ secret: string; otpauth_uri: string }>("profile.totp-begin", {});
    totpSecret.value = res.secret;
    totpUri.value = res.otpauth_uri;
    qrSvg.value = qrSvgPath(res.otpauth_uri);
    recoveryCodes.value = [];
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to initialize 2FA.", "destructive");
  } finally {
    busy.value = false;
  }
}

async function confirmTotp() {
  busy.value = true;
  try {
    const res = await apiPost<{ recovery_codes: string[] }>("profile.totp-confirm", { code: confirmCode.value });
    recoveryCodes.value = res.recovery_codes || [];
    totpSecret.value = "";
    totpUri.value = "";
    qrSvg.value = null;
    confirmCode.value = "";
    await refreshAuthStatus();
    notifyToast("Two-factor authentication activated. Save your recovery codes!", "success");
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Verification code invalid.", "destructive");
  } finally {
    busy.value = false;
  }
}

async function copyRecoveryCodes() {
  const ok = await copyText(recoveryCodes.value.join("\n"));
  if (ok) {
    copiedCodes.value = true;
    notifyToast("Recovery codes copied to clipboard.", "success");
    window.setTimeout(() => {
      copiedCodes.value = false;
    }, 2000);
  } else {
    notifyToast("Clipboard unavailable — please save codes manually.", "destructive");
  }
}

function downloadRecoveryCodes() {
  const txt = [
    `# ${adminStore.boot.branding.title} — 2FA Recovery Codes`,
    `# Account: ${adminStore.user?.email || ""}`,
    `# Generated: ${new Date().toISOString()}`,
    "",
    ...recoveryCodes.value,
    "",
  ].join("\n");
  const blob = new Blob([txt], { type: "text/plain;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = "lumora-2fa-recovery-codes.txt";
  document.body.append(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

async function disableTotp() {
  busy.value = true;
  try {
    await apiPost("profile.totp-disable", {
      password: disablePassword.value,
      code: disableCode.value,
    });
    disablePassword.value = "";
    disableCode.value = "";
    await refreshAuthStatus();
    notifyToast("Two-factor authentication disabled.");
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to disable 2FA.", "destructive");
  } finally {
    busy.value = false;
  }
}

function requestRevokeSession(id: number) {
  revokeTargetId.value = id;
  revokeConfirmOpen.value = true;
}

async function executeRevokeSession() {
  if (revokeTargetId.value === null) return;
  busy.value = true;
  try {
    await apiPost("profile.revoke-session", { id: revokeTargetId.value });
    notifyToast("Session revoked immediately.", "success");
    revokeConfirmOpen.value = false;
    revokeTargetId.value = null;
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Session revoke failed.", "destructive");
  } finally {
    busy.value = false;
  }
}

async function executeRevokeAllOthers() {
  if (otherSessions.value.length === 0) return;
  busy.value = true;
  try {
    for (const s of otherSessions.value) {
      await apiPost("profile.revoke-session", { id: s.id });
    }
    notifyToast(`Signed out ${otherSessions.value.length} other session(s).`, "success");
    revokeAllOthersConfirmOpen.value = false;
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Could not revoke all sessions.", "destructive");
  } finally {
    busy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="space-y-6">
    <div>
      <h2 class="text-xl font-semibold tracking-tight">Profile &amp; Two-Factor Security</h2>
      <p class="text-sm text-muted-foreground">Manage your account identity, credentials, RFC 6238 TOTP authenticator, and active sessions.</p>
    </div>

    <div
      v-if="adminStore.requires2faSetup"
      role="alert"
      class="rounded-md border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-300"
    >
      This site enforces mandatory 2FA. Complete TOTP enrollment below to unlock console modules.
    </div>

    <Card class="overflow-hidden">
      <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 class="text-sm font-semibold">Operator Identity</h3>
          <p class="text-xs text-muted-foreground">Account display details recorded on audit trails and content revisions.</p>
        </div>
        <Badge variant="outline" class="uppercase font-mono text-[11px]">{{ adminStore.user?.role }}</Badge>
      </div>
      <form id="profile-identity-form" class="p-5 grid gap-4 sm:grid-cols-2" @submit.prevent="updateDisplayName">
        <div class="space-y-1">
          <label for="prof-name" class="text-xs font-medium">Display Name</label>
          <Input id="prof-name" v-model="displayName" required maxlength="120" />
        </div>
        <div class="space-y-1">
          <label for="prof-email" class="text-xs font-medium">Email Address (Read-Only)</label>
          <Input id="prof-email" :model-value="adminStore.user?.email || ''" disabled />
        </div>
      </form>
      <div class="px-5 py-3 border-t border-border bg-muted/30 flex items-center justify-between gap-3">
        <span class="text-xs text-muted-foreground">Last login: {{ formatDateTime(adminStore.user?.last_login_at) }}</span>
        <Button type="submit" form="profile-identity-form" size="sm" :disabled="busy">Update Profile</Button>
      </div>
    </Card>

    <div class="grid gap-5 lg:grid-cols-2 items-stretch">
      <Card class="overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-border flex items-center justify-between gap-2">
          <div class="flex items-center gap-2 font-semibold text-sm">
            <KeyRound class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
            <span>Authenticator App (TOTP)</span>
          </div>
          <Badge :variant="adminStore.user?.totp_enabled ? 'success' : 'outline'">
            {{ adminStore.user?.totp_enabled ? "Enabled" : "Not Enrolled" }}
          </Badge>
        </div>

        <div class="p-5 flex-1 space-y-4">
          <div v-if="recoveryCodes.length > 0" class="rounded-md border border-emerald-500/40 bg-emerald-500/10 p-4 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="text-xs font-semibold">Single-Use Recovery Codes (shown once)</span>
              <div class="flex gap-1.5">
                <Button variant="outline" size="sm" @click="copyRecoveryCodes">
                  <Check v-if="copiedCodes" class="h-3.5 w-3.5" aria-hidden="true" />
                  <Copy v-else class="h-3.5 w-3.5" aria-hidden="true" />
                  <span>{{ copiedCodes ? "Copied" : "Copy All" }}</span>
                </Button>
                <Button variant="outline" size="sm" @click="downloadRecoveryCodes">
                  <Download class="h-3.5 w-3.5" aria-hidden="true" />
                  <span>Download .txt</span>
                </Button>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-1.5 font-mono text-xs">
              <code v-for="rc in recoveryCodes" :key="rc" class="rounded bg-background px-2 py-1 border border-border">{{ rc }}</code>
            </div>
          </div>

          <div v-if="!adminStore.user?.totp_enabled" class="space-y-4">
            <p class="text-xs text-muted-foreground leading-relaxed">
              Protect this operator account with an RFC 6238 authenticator app (1Password, Bitwarden, Google Authenticator, or Apple Passwords).
            </p>
            <div v-if="totpSecret" class="space-y-4">
              <div v-if="qrSvg" class="inline-block rounded-md border border-border bg-white p-3">
                <svg
                  :viewBox="qrSvg.viewBox"
                  width="176"
                  height="176"
                  role="img"
                  aria-label="TOTP enrollment QR code"
                  class="h-44 w-44 text-black"
                >
                  <path :d="qrSvg.path" fill="currentColor" />
                </svg>
              </div>
              <div class="space-y-1 text-xs">
                <div class="text-muted-foreground">Manual Secret Key:</div>
                <code class="block rounded bg-muted px-2.5 py-1.5 font-mono select-all">{{ totpSecret }}</code>
              </div>
              <form class="flex items-end gap-2" @submit.prevent="confirmTotp">
                <div class="flex-1 space-y-1">
                  <label for="totp-confirm" class="text-xs font-medium">Enter 6-digit code to activate</label>
                  <Input id="totp-confirm" v-model="confirmCode" inputmode="numeric" autocomplete="one-time-code" required placeholder="123456" />
                </div>
                <Button type="submit" :disabled="busy">Verify</Button>
              </form>
            </div>
          </div>

          <form v-else-if="!adminStore.boot.require2fa" id="disable-totp-form" class="space-y-3" @submit.prevent="disableTotp">
            <p class="text-xs text-muted-foreground">Enter your current password and an authenticator or recovery code to disable 2FA.</p>
            <div class="grid gap-3 sm:grid-cols-2">
              <div class="space-y-1">
                <label for="dis-pw" class="text-xs font-medium">Current Password</label>
                <Input id="dis-pw" v-model="disablePassword" type="password" autocomplete="current-password" required placeholder="Password" />
              </div>
              <div class="space-y-1">
                <label for="dis-code" class="text-xs font-medium">TOTP or Recovery Code</label>
                <Input id="dis-code" v-model="disableCode" inputmode="numeric" required placeholder="6-digit code" />
              </div>
            </div>
          </form>

          <div v-else class="text-xs text-muted-foreground">
            Mandatory 2FA policy is enabled globally in configuration. Two-factor authentication cannot be disabled.
          </div>
        </div>

        <div
          v-if="(!adminStore.user?.totp_enabled && !totpSecret) || (adminStore.user?.totp_enabled && !adminStore.boot.require2fa)"
          class="px-5 py-3 border-t border-border bg-muted/30 flex justify-end"
        >
          <Button v-if="!adminStore.user?.totp_enabled && !totpSecret" size="sm" :disabled="busy" @click="beginTotp">
            <ShieldCheck class="h-4 w-4" aria-hidden="true" />
            <span>Enroll Authenticator App</span>
          </Button>
          <Button v-else type="submit" form="disable-totp-form" variant="destructive" size="sm" :disabled="busy">
            <ShieldOff class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Disable 2FA</span>
          </Button>
        </div>
      </Card>

      <Card class="overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-border">
          <h3 class="text-sm font-semibold">Change Password</h3>
          <p class="text-xs text-muted-foreground">Updating your password signs out all other active sessions automatically.</p>
        </div>
        <form id="profile-password-form" class="p-5 flex-1 space-y-3" @submit.prevent="changePassword">
          <div class="space-y-1">
            <label for="cp-cur" class="text-xs font-medium">Current Password</label>
            <Input id="cp-cur" v-model="currentPassword" type="password" autocomplete="current-password" required />
          </div>
          <div class="space-y-1">
            <label for="cp-next" class="text-xs font-medium">New Password (min 10 chars)</label>
            <Input id="cp-next" v-model="newPassword" type="password" autocomplete="new-password" minlength="10" required />
          </div>
        </form>
        <div class="px-5 py-3 border-t border-border bg-muted/30 flex justify-end">
          <Button type="submit" form="profile-password-form" size="sm" :disabled="busy">Update Password</Button>
        </div>
      </Card>
    </div>

    <Card class="overflow-hidden">
      <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 class="text-sm font-semibold">Your Active Sessions ({{ sessions.length }})</h3>
          <p class="text-xs text-muted-foreground">Authenticated browser sessions currently active for your account.</p>
        </div>
        <Button
          v-if="otherSessions.length > 0"
          variant="outline"
          size="sm"
          :disabled="busy"
          @click="revokeAllOthersConfirmOpen = true"
        >
          <ShieldAlert class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
          <span>Sign Out Other Sessions ({{ otherSessions.length }})</span>
        </Button>
      </div>
      <div class="divide-y divide-border text-xs">
        <div v-if="sessions.length === 0" class="p-5 text-center text-muted-foreground">
          No active sessions loaded.
        </div>
        <div v-for="s in sessions" :key="s.id" class="px-5 py-3 flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-2.5">
            <Badge v-if="s.is_current" variant="success">Current Session</Badge>
            <Badge v-else variant="outline">Remote Device</Badge>
            <span class="font-mono text-muted-foreground">IP {{ s.ip_hash_short }}…</span>
          </div>
          <div class="flex flex-wrap items-center gap-4">
            <div class="text-muted-foreground font-mono">
              <span>Seen: {{ formatDateTime(s.last_seen_at) }}</span>
              <span class="mx-1.5">·</span>
              <span>Expires: {{ formatDateTime(s.expires_at) }}</span>
            </div>
            <Button
              v-if="!s.is_current"
              variant="ghost"
              size="sm"
              :aria-label="`Revoke session #${s.id}`"
              @click="requestRevokeSession(s.id)"
            >
              <Trash2 class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
              <span>Revoke</span>
            </Button>
          </div>
        </div>
      </div>
    </Card>

    <ConfirmDialog
      v-model:open="revokeConfirmOpen"
      title="Revoke Active Session?"
      description="Immediately sign out the selected device/browser session?"
      confirm-label="Revoke Session"
      variant="destructive"
      :loading="busy"
      @confirm="executeRevokeSession"
    />

    <ConfirmDialog
      v-model:open="revokeAllOthersConfirmOpen"
      title="Sign Out All Other Sessions?"
      :description="`Immediately revoke ${otherSessions.length} other authenticated session(s) while keeping your current session signed in?`"
      confirm-label="Sign Out Others"
      variant="destructive"
      :loading="busy"
      @confirm="executeRevokeAllOthers"
    />
  </div>
</template>
