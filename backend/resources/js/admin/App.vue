<script setup lang="ts">
function csrfToken(): string {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}
</script>

<template>
  <div class="min-h-screen bg-slate-950 text-slate-100">
    <nav class="flex items-center justify-between border-b border-slate-800 px-6 py-3">
      <div class="flex items-center gap-6">
        <span class="font-semibold tracking-tight">Signage Admin</span>
        <RouterLink
          to="/"
          class="text-sm text-slate-400 hover:text-slate-100"
          active-class="!text-slate-100 font-medium"
          exact-active-class="!text-slate-100 font-medium"
        >
          Dashboard
        </RouterLink>
        <RouterLink
          to="/media-items"
          class="text-sm text-slate-400 hover:text-slate-100"
          active-class="!text-slate-100 font-medium"
        >
          Media Items
        </RouterLink>
        <RouterLink
          to="/devices"
          class="text-sm text-slate-400 hover:text-slate-100"
          active-class="!text-slate-100 font-medium"
        >
          Devices
        </RouterLink>
        <RouterLink
          to="/integrations"
          class="text-sm text-slate-400 hover:text-slate-100"
          active-class="!text-slate-100 font-medium"
        >
          Integrations
        </RouterLink>
      </div>

      <!--
        A plain HTML form POST, not a fetch() call: logout ends the
        session, so the correct outcome is a full page navigation to the
        login screen — there's no client-side state worth preserving
        through an XHR here.
      -->
      <form method="POST" action="/admin/logout">
        <input type="hidden" name="_token" :value="csrfToken()" />
        <button type="submit" class="text-sm text-slate-400 hover:text-slate-100">Sign out</button>
      </form>
    </nav>

    <main class="mx-auto max-w-5xl px-6 py-8">
      <RouterView />
    </main>
  </div>
</template>
