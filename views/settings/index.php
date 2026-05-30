<?php $pageTitle = 'Settings'; ?>

<div class="space-y-6">
    <h1 class="text-3xl font-bold text-gray-900">Settings</h1>

    <!-- Settings Tabs -->
    <div class="border-b border-gray-200">
        <nav class="flex gap-8">
            <a href="#zabbix" class="px-4 py-2 border-b-2 border-blue-600 text-blue-600 font-medium">Zabbix</a>
            <a href="#vault" class="px-4 py-2 text-gray-600 hover:text-gray-900">Vault</a>
            <a href="#vendors" class="px-4 py-2 text-gray-600 hover:text-gray-900">Vendors</a>
        </nav>
    </div>

    <!-- Zabbix Settings -->
    <div id="zabbix" class="bg-white rounded-lg border border-gray-200 p-6 max-w-2xl">
        <h2 class="text-lg font-bold text-gray-900 mb-6">Zabbix Integration</h2>

        <div x-data="zabbixSettings()" x-init="loadSettings()" class="space-y-6">
            <!-- API Health -->
            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="font-medium text-gray-900">API Health</p>
                        <p class="text-sm text-gray-600 mt-1">Last checked: <span x-text="lastChecked"></span></p>
                    </div>
                    <button @click="checkHealth()" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                        Check Now
                    </button>
                </div>
            </div>

            <!-- Status -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Connection Status</label>
                <div :class="status === 'connected' ? 'bg-green-100 border-green-300 text-green-800' : 'bg-red-100 border-red-300 text-red-800'"
                     class="px-4 py-3 border rounded">
                    <span x-text="status === 'connected' ? '✓ Connected' : '✗ Not Connected'"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Vault Settings -->
    <div id="vault" class="bg-white rounded-lg border border-gray-200 p-6 max-w-2xl mt-8">
        <h2 class="text-lg font-bold text-gray-900 mb-6">Vault Integration</h2>

        <div x-data="vaultSettings()" x-init="loadSettings()" class="space-y-6">
            <!-- Health -->
            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="font-medium text-gray-900">Vault Health</p>
                        <p class="text-sm text-gray-600 mt-1">Secrets Stored: <span x-text="secretCount"></span></p>
                    </div>
                    <button @click="checkHealth()" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                        Sync
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Vendor Health -->
    <div id="vendors" class="bg-white rounded-lg border border-gray-200 p-6 max-w-4xl mt-8">
        <h2 class="text-lg font-bold text-gray-900 mb-6">Vendor API Health</h2>

        <div x-data="vendorHealth()" x-init="loadVendors()" class="space-y-4">
            <template x-for="vendor in vendors" :key="vendor.name">
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="font-medium text-gray-900" x-text="vendor.name"></h3>
                            <p class="text-sm text-gray-600" x-text="vendor.description"></p>
                        </div>
                        <div class="text-right">
                            <span :class="vendor.status === 'healthy' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                  class="px-3 py-1 rounded-full text-xs font-medium"
                                  x-text="vendor.status"></span>
                            <p class="text-xs text-gray-500 mt-2">Last check: <span x-text="vendor.last_check"></span></p>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function zabbixSettings() {
    return {
        status: 'connected',
        lastChecked: 'Just now',
        async checkHealth() {
            // Check Zabbix health
        },
        async loadSettings() {}
    };
}

function vaultSettings() {
    return {
        secretCount: 24,
        async checkHealth() {},
        async loadSettings() {}
    };
}

function vendorHealth() {
    return {
        vendors: [],
        async loadVendors() {
            try {
                const response = await fetch('/api/settings/vendors', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.vendors = data.vendors || [];
                }
            } catch (e) {
                console.error('Failed to load vendors:', e);
            }
        }
    };
}
</script>
