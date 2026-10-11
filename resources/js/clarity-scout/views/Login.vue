<template>
    <div class="flex items-center justify-center min-h-screen px-4" style="background-color: hsl(227, 100%, 22%)">
        <div class="w-full max-w-sm p-8 bg-white rounded-xl shadow-lg">
            <h1 class="text-2xl font-bold text-center text-gray-900 mb-2">Clarity</h1>
            <p class="text-sm text-center text-gray-500 mb-8">Clarity Scout</p>

            <div
                v-if="signedOut"
                class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800"
            >
                <p class="font-semibold">You were signed out</p>
                <p class="mt-1">{{ signedOut.message || 'Your session is no longer valid. Sign in again to continue.' }}</p>
                <p class="mt-1 text-xs text-amber-700">{{ signedOut.status }} &middot; {{ signedOut.path }}</p>
            </div>

            <div
                v-if="error"
                class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700 font-semibold"
            >
                {{ error }}
            </div>

            <div
                v-if="resetSent"
                class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800"
            >
                {{ resetSent }}
            </div>

            <form v-if="!forgotMode" @submit.prevent="handleLogin" class="space-y-5">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input
                        id="email"
                        v-model="email"
                        type="email"
                        required
                        autocomplete="email"
                        class="block w-full px-4 py-3 min-h-[48px] text-base text-gray-900 bg-white border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#FF6666]/30 focus:border-[#FF6666] placeholder-gray-400"
                        placeholder="you@example.com"
                    />
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input
                        id="password"
                        v-model="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="block w-full px-4 py-3 min-h-[48px] text-base text-gray-900 bg-white border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#FF6666]/30 focus:border-[#FF6666] placeholder-gray-400"
                        placeholder="Enter your password"
                    />
                </div>

                <button
                    type="submit"
                    :disabled="loading"
                    class="w-full py-3 px-4 min-h-[48px] text-base font-medium text-white bg-[#FF6666] rounded-lg hover:bg-[#e55a5a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#FF6666] disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <span v-if="loading">Signing in...</span>
                    <span v-else>Sign In</span>
                </button>

                <button
                    type="button"
                    class="w-full text-sm text-[#FF6666] hover:underline min-h-[44px]"
                    @click="openForgot"
                >
                    Forgot password?
                </button>
            </form>

            <form v-else @submit.prevent="handleForgot" class="space-y-5">
                <p class="text-sm text-gray-600">
                    Enter your email and we will send a password reset link if that account uses Clarity Scout.
                </p>

                <div>
                    <label for="forgot-email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input
                        id="forgot-email"
                        v-model="email"
                        type="email"
                        required
                        autocomplete="email"
                        class="block w-full px-4 py-3 min-h-[48px] text-base text-gray-900 bg-white border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#FF6666]/30 focus:border-[#FF6666] placeholder-gray-400"
                        placeholder="you@example.com"
                    />
                </div>

                <button
                    type="submit"
                    :disabled="loading"
                    class="w-full py-3 px-4 min-h-[48px] text-base font-medium text-white bg-[#FF6666] rounded-lg hover:bg-[#e55a5a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#FF6666] disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <span v-if="loading">Sending...</span>
                    <span v-else>Send reset link</span>
                </button>

                <button
                    type="button"
                    class="w-full text-sm text-gray-600 hover:underline min-h-[44px]"
                    @click="closeForgot"
                >
                    Back to sign in
                </button>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { takeSignedOutReason } from '../lib/fetch';

const router = useRouter();
const auth = useAuthStore();

const email = ref('');
const password = ref('');
const loading = ref(false);
const error = ref('');
const resetSent = ref('');
const forgotMode = ref(false);
const signedOut = ref(takeSignedOutReason());

function openForgot() {
    forgotMode.value = true;
    error.value = '';
    resetSent.value = '';
}

function closeForgot() {
    forgotMode.value = false;
    error.value = '';
}

async function handleLogin() {
    loading.value = true;
    error.value = '';
    resetSent.value = '';

    try {
        await auth.login(email.value, password.value);
        router.push({ name: 'app' });
    } catch (err) {
        error.value = err?.response?.data?.message || 'An unexpected error occurred. Please try again.';
    } finally {
        loading.value = false;
    }
}

async function handleForgot() {
    loading.value = true;
    error.value = '';
    resetSent.value = '';

    try {
        const data = await auth.forgotPassword(email.value);
        resetSent.value = data?.message || 'If that email is registered for Clarity Scout, we sent a reset link.';
        forgotMode.value = false;
    } catch (err) {
        error.value = err?.response?.data?.message || 'An unexpected error occurred. Please try again.';
    } finally {
        loading.value = false;
    }
}
</script>
