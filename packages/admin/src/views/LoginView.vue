<script setup lang="ts">
import { computed, ref } from "vue";
import { Eye, EyeOff, KeyRound, Lock, Moon, ShieldCheck, Sun } from "lucide-vue-next";
import { apiPost } from "../lib/api";
import type { AuthStatusResponse } from "../lib/types";
import { adminStore, applyThemePreference, notifyToast, refreshAuthStatus } from "../stores/admin";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import Input from "../components/ui/Input.vue";

const email = ref("");
const name = ref("");
const password = ref("");
const totpCode = ref("");
const setupToken = ref("");
const showPassword = ref(false);
const submitting = ref(false);
const errorMsg = ref("");

const isSetupMode = computed(() => !adminStore.hasOwner && adminStore.setupAvailable);
const isStep2 = computed(() => adminStore.requires2fa);

const activeLogo = computed(() => {
  if (adminStore.resolvedDark && adminStore.boot.branding.logoDark) {
    return adminStore.boot.branding.logoDark;
  }
  return adminStore.boot.branding.logo || "";
});

function toggleTheme() {
  applyThemePreference(adminStore.resolvedDark ? "light" : "dark");
}

async function handleSignIn() {
  errorMsg.value = "";
  submitting.value = true;
  try {
    await apiPost<AuthStatusResponse>(
      "auth.login",
      {
        email: email.value,
        password: password.value,
        code: totpCode.value,
      },
      true
    );
    password.value = "";
    totpCode.value = "";
    await refreshAuthStatus();
    if (adminStore.authenticated) {
      notifyToast("Signed in cleanly.", "success");
    }
  } catch (err) {
    errorMsg.value = err instanceof Error ? err.message : "Authentication failed.";
  } finally {
    submitting.value = false;
  }
}

async function handleVerify2fa() {
  errorMsg.value = "";
  submitting.value = true;
  try {
    await apiPost<AuthStatusResponse>("auth.verify-2fa", { code: totpCode.value }, false);
    totpCode.value = "";
    await refreshAuthStatus();
    notifyToast("Two-factor check verified.", "success");
  } catch (err) {
    errorMsg.value = err instanceof Error ? err.message : "Invalid verification code.";
  } finally {
    submitting.value = false;
  }
}

async function handleInitialSetup() {
  errorMsg.value = "";
  submitting.value = true;
  try {
    await apiPost<AuthStatusResponse>(
      "auth.setup",
      {
        setup_token: setupToken.value,
        name: name.value,
        email: email.value,
        password: password.value,
      },
      true
    );
    setupToken.value = "";
    password.value = "";
    await refreshAuthStatus();
    notifyToast("Owner account initialized.", "success");
  } catch (err) {
    errorMsg.value = err instanceof Error ? err.message : "Initial owner setup failed.";
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <main class="min-h-screen flex flex-col items-center justify-center p-4 bg-background">
    <div class="w-full max-w-md space-y-5">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <img
            v-if="activeLogo"
            :src="activeLogo"
            :alt="adminStore.boot.branding.brandName"
            width="28"
            height="28"
            class="h-7 w-7 rounded object-contain"
          />
          <div
            v-else
            class="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-primary-foreground text-xs font-bold"
          >
            {{ adminStore.boot.branding.brandName.slice(0, 2).toUpperCase() }}
          </div>
          <div>
            <h1 class="text-base font-semibold tracking-tight">{{ adminStore.boot.branding.title }}</h1>
            <p class="text-xs text-muted-foreground">{{ adminStore.boot.branding.domain }}</p>
          </div>
        </div>
        <Button variant="ghost" size="icon" aria-label="Toggle color theme" @click="toggleTheme">
          <Sun v-if="adminStore.resolvedDark" class="h-4 w-4" />
          <Moon v-else class="h-4 w-4" />
        </Button>
      </div>

      <Card class="p-6">
        <div
          v-if="!adminStore.hasOwner && !adminStore.setupAvailable"
          class="mb-4 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300"
          role="status"
        >
          No active owner exists yet. Create one via CLI:
          <code class="block mt-1 font-mono">bunx lumora-web admin create-user --email admin@example.com --role owner</code>
          or configure <code class="font-mono">admin.initial_owner</code> in <code class="font-mono">lumora.config.php</code>.
        </div>

        <div
          v-if="errorMsg"
          role="alert"
          aria-live="polite"
          class="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
        >
          {{ errorMsg }}
        </div>

        <!-- State 1: Initial Setup Token -->
        <form v-if="isSetupMode" class="space-y-4" @submit.prevent="handleInitialSetup">
          <div class="flex items-center gap-2 text-sm font-semibold">
            <ShieldCheck class="h-4 w-4" />
            <span>Initialize Owner Account</span>
          </div>
          <div class="space-y-1.5">
            <label for="setup-token" class="text-xs font-medium">Bootstrap Token</label>
            <Input
              id="setup-token"
              v-model="setupToken"
              type="password"
              name="setup_token"
              autocomplete="off"
              spellcheck="false"
              required
              placeholder="LUMORA_ADMIN_SETUP_TOKEN"
            />
          </div>
          <div class="space-y-1.5">
            <label for="setup-name" class="text-xs font-medium">Full Name</label>
            <Input id="setup-name" v-model="name" type="text" name="name" autocomplete="name" required />
          </div>
          <div class="space-y-1.5">
            <label for="setup-email" class="text-xs font-medium">Email Address</label>
            <Input
              id="setup-email"
              v-model="email"
              type="email"
              name="email"
              autocomplete="username"
              spellcheck="false"
              required
            />
          </div>
          <div class="space-y-1.5">
            <label for="setup-pass" class="text-xs font-medium">Password (min 10 chars)</label>
            <Input
              id="setup-pass"
              v-model="password"
              type="password"
              name="password"
              autocomplete="new-password"
              minlength="10"
              required
            />
          </div>
          <Button type="submit" class="w-full" :disabled="submitting">
            {{ submitting ? "Initializing..." : "Create Owner Account" }}
          </Button>
        </form>

        <!-- State 2: Step-2 Two-Factor Verification -->
        <form v-else-if="isStep2" class="space-y-4" @submit.prevent="handleVerify2fa">
          <div class="flex items-center gap-2 text-sm font-semibold">
            <KeyRound class="h-4 w-4" />
            <span>Two-Factor Verification</span>
          </div>
          <p class="text-xs text-muted-foreground">
            Enter your 6-digit authenticator code or a single-use backup code for
            <strong class="text-foreground">{{ adminStore.user?.email }}</strong>.
          </p>
          <div class="space-y-1.5">
            <label for="totp-code" class="text-xs font-medium">Verification Code</label>
            <Input
              id="totp-code"
              v-model="totpCode"
              type="text"
              name="code"
              inputmode="numeric"
              autocomplete="one-time-code"
              autocapitalize="none"
              spellcheck="false"
              required
              placeholder="123456 or A1B2-C3D4"
            />
          </div>
          <Button type="submit" class="w-full" :disabled="submitting">
            {{ submitting ? "Verifying..." : "Verify & Continue" }}
          </Button>
        </form>

        <!-- State 3: Primary Credentials Sign-In -->
        <form v-else class="space-y-4" @submit.prevent="handleSignIn">
          <div class="flex items-center gap-2 text-sm font-semibold">
            <Lock class="h-4 w-4" />
            <span>Operator Sign In</span>
          </div>
          <div class="space-y-1.5">
            <label for="login-email" class="text-xs font-medium">Email Address</label>
            <Input
              id="login-email"
              v-model="email"
              type="email"
              name="email"
              autocomplete="username"
              spellcheck="false"
              required
              placeholder="operator@domain.com"
            />
          </div>
          <div class="space-y-1.5">
            <label for="login-password" class="text-xs font-medium">Password</label>
            <div class="relative">
              <Input
                id="login-password"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                name="password"
                autocomplete="current-password"
                required
                class="pr-10"
              />
              <button
                type="button"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground hover:text-foreground cursor-pointer"
                :aria-pressed="showPassword"
                aria-label="Toggle password visibility"
                @click="showPassword = !showPassword"
              >
                <EyeOff v-if="showPassword" class="h-4 w-4" />
                <Eye v-else class="h-4 w-4" />
              </button>
            </div>
          </div>
          <Button type="submit" class="w-full" :disabled="submitting">
            {{ submitting ? "Signing in..." : "Sign In" }}
          </Button>
        </form>
      </Card>

      <p class="text-center text-xs text-muted-foreground">
        <a :href="adminStore.boot.siteUrl" class="underline underline-offset-2 hover:text-foreground">
          Return to {{ adminStore.boot.branding.brandName }}
        </a>
      </p>
    </div>
  </main>
</template>
