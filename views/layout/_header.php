<header class="bg-white border-b border-gray-200 px-6 py-4 flex justify-between items-center">
    <!-- Breadcrumb -->
    <div class="text-sm text-gray-600">
        <template x-if="breadcrumb && breadcrumb.length > 0">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex space-x-2">
                    <template x-for="(item, idx) in breadcrumb" :key="idx">
                        <li class="flex items-center">
                            <template x-if="item.url">
                                <a :href="item.url" class="text-blue-600 hover:underline" x-text="item.label"></a>
                            </template>
                            <template x-if="!item.url">
                                <span x-text="item.label"></span>
                            </template>
                            <template x-if="idx < breadcrumb.length - 1">
                                <span class="mx-2">/</span>
                            </template>
                        </li>
                    </template>
                </ol>
            </nav>
        </template>
        <template x-if="!breadcrumb || breadcrumb.length === 0">
            <span>Dashboard</span>
        </template>
    </div>

    <!-- Right Actions -->
    <div class="flex items-center space-x-4">
        <!-- Notifications (placeholder) -->
        <button class="relative p-2 hover:bg-gray-100 rounded-lg">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
            </svg>
            <span class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"></span>
        </button>

        <!-- User Menu -->
        <div @click.away="userMenuOpen = false" class="relative">
            <button @click="userMenuOpen = !userMenuOpen" class="flex items-center space-x-2 p-2 hover:bg-gray-100 rounded-lg">
                <div class="w-8 h-8 bg-gray-300 rounded-full"></div>
                <span class="text-sm font-medium" x-text="$store.auth.user?.name || 'User'"></span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                </svg>
            </button>

            <!-- Dropdown Menu -->
            <div x-show="userMenuOpen" class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 z-50">
                <a href="/profile" class="block px-4 py-2 text-sm hover:bg-gray-100">Profile</a>
                <a href="/settings" class="block px-4 py-2 text-sm hover:bg-gray-100">Settings</a>
                <hr class="my-2">
                <button @click="logout()" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100">Logout</button>
            </div>
        </div>
    </div>
</header>

<script>
// Header state
document.addEventListener('alpine:initialized', () => {
    if (!window.Alpine.store('sidebarState')) {
        Alpine.store('sidebarState', {});
    }
});
</script>
