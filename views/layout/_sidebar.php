<nav class="w-64 bg-gray-900 text-white flex flex-col" @click.away="sidebarOpen = false">
    <!-- Logo/Title -->
    <div class="px-6 py-4 border-b border-gray-700">
        <h1 class="text-xl font-bold">NMS</h1>
        <p class="text-xs text-gray-400">Network Management</p>
    </div>

    <!-- Navigation Links -->
    <ul class="flex-1 overflow-y-auto px-4 py-6 space-y-1">
        <!-- Dashboard -->
        <li><a href="/dashboard" class="block px-4 py-2 rounded hover:bg-gray-800">📊 Dashboard</a></li>

        <!-- Devices & Clusters -->
        <li class="space-y-1">
            <button @click="expand.devices = !expand.devices" class="w-full text-left px-4 py-2 rounded hover:bg-gray-800 flex justify-between items-center">
                <span>🖥️ Devices & Clusters</span>
                <span x-show="!expand.devices">→</span>
                <span x-show="expand.devices">↓</span>
            </button>
            <ul x-show="expand.devices" class="space-y-1 ml-4">
                <li><a href="/devices" class="block px-4 py-2 text-sm rounded hover:bg-gray-800">Devices</a></li>
                <li><a href="/clusters" class="block px-4 py-2 text-sm rounded hover:bg-gray-800">Clusters</a></li>
            </ul>
        </li>

        <!-- Infrastructure -->
        <li class="space-y-1">
            <button @click="expand.infra = !expand.infra" class="w-full text-left px-4 py-2 rounded hover:bg-gray-800 flex justify-between items-center">
                <span>🏢 Infrastructure</span>
                <span x-show="!expand.infra">→</span>
                <span x-show="expand.infra">↓</span>
            </button>
            <ul x-show="expand.infra" class="space-y-1 ml-4">
                <li><a href="/sites" class="block px-4 py-2 text-sm rounded hover:bg-gray-800">Sites</a></li>
                <li><a href="/racks" class="block px-4 py-2 text-sm rounded hover:bg-gray-800">Racks</a></li>
            </ul>
        </li>

        <!-- Topology -->
        <li class="space-y-1">
            <button @click="expand.topology = !expand.topology" class="w-full text-left px-4 py-2 rounded hover:bg-gray-800 flex justify-between items-center">
                <span>🌐 Topology</span>
                <span x-show="!expand.topology">→</span>
                <span x-show="expand.topology">↓</span>
            </button>
            <ul x-show="expand.topology" class="space-y-1 ml-4">
                <li><a href="/topology/logical" class="block px-4 py-2 text-sm rounded hover:bg-gray-800">Logical View</a></li>
                <li><a href="/topology/physical" class="block px-4 py-2 text-sm rounded hover:bg-gray-800">Physical View</a></li>
            </ul>
        </li>

        <!-- IPAM -->
        <li><a href="/ipam" class="block px-4 py-2 rounded hover:bg-gray-800">📍 IPAM</a></li>

        <!-- Firewall -->
        <li class="space-y-1">
            <button @click="expand.firewall = !expand.firewall" class="w-full text-left px-4 py-2 rounded hover:bg-gray-800 flex justify-between items-center">
                <span>🔥 Firewall</span>
                <span x-show="!expand.firewall">→</span>
                <span x-show="expand.firewall">↓</span>
            </button>
            <ul x-show="expand.firewall" class="space-y-1 ml-4">
                <li><a href="/firewall/policies" class="block px-4 py-2 text-sm rounded hover:bg-gray-800">Policies</a></li>
                <li><a href="/firewall/objects" class="block px-4 py-2 text-sm rounded hover:bg-gray-800">Objects</a></li>
            </ul>
        </li>

        <!-- Drift Detection -->
        <li><a href="/drift" class="block px-4 py-2 rounded hover:bg-gray-800">⚠️ Drift Detection</a></li>

        <!-- NICs -->
        <li><a href="/nics" class="block px-4 py-2 rounded hover:bg-gray-800">🔌 Server NICs</a></li>

        <!-- VPN -->
        <li><a href="/vpn" class="block px-4 py-2 rounded hover:bg-gray-800">🔐 VPN</a></li>

        <!-- Audit & Settings -->
        <li class="space-y-1">
            <button @click="expand.admin = !expand.admin" class="w-full text-left px-4 py-2 rounded hover:bg-gray-800 flex justify-between items-center">
                <span>⚙️ Admin</span>
                <span x-show="!expand.admin">→</span>
                <span x-show="expand.admin">↓</span>
            </button>
            <ul x-show="expand.admin" class="space-y-1 ml-4">
                <li><a href="/audit" class="block px-4 py-2 text-sm rounded hover:bg-gray-800">Audit Logs</a></li>
                <li><a href="/settings" class="block px-4 py-2 text-sm rounded hover:bg-gray-800">Settings</a></li>
            </ul>
        </li>
    </ul>

    <!-- User Section -->
    <div class="border-t border-gray-700 px-4 py-4">
        <div class="text-sm">
            <p class="font-semibold" x-text="$store.auth.user?.name || 'User'"></p>
            <p class="text-xs text-gray-400" x-text="$store.auth.user?.email || ''"></p>
        </div>
        <button @click="logout()" class="mt-3 w-full px-4 py-2 bg-gray-800 rounded hover:bg-gray-700 text-sm">
            Logout
        </button>
    </div>
</nav>

<script>
// Sidebar state management
document.addEventListener('alpine:initialized', () => {
    Alpine.store('sidebarState', {
        devices: false,
        infra: false,
        topology: false,
        firewall: false,
        admin: false
    });
});
</script>
