<?php $pageTitle = 'Device Details'; ?>

<div class="space-y-6" x-data="deviceView()" x-init="loadDevice()">
    <!-- Header -->
    <div class="flex justify-between items-start">
        <div>
            <h1 class="text-3xl font-bold text-gray-900" x-text="device.name"></h1>
            <p class="text-gray-600" x-text="device.ip_address"></p>
        </div>
        <div class="flex gap-3">
            <a href="#" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Edit</a>
            <a href="#" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">Delete</a>
        </div>
    </div>

    <!-- Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <p class="text-gray-600 text-sm mb-2">Status</p>
            <p :class="device.status === 'online' ? 'text-green-600' : 'text-red-600'" class="text-2xl font-bold" x-text="device.status"></p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <p class="text-gray-600 text-sm mb-2">Drift Status</p>
            <p :class="device.drift?.status === 'drifted' ? 'text-yellow-600' : 'text-green-600'" class="text-2xl font-bold">
                <span x-show="device.drift?.status === 'drifted'">⚠️ Drifted</span>
                <span x-show="device.drift?.status !== 'drifted'">✓ Clean</span>
            </p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <p class="text-gray-600 text-sm mb-2">Health</p>
            <p class="text-2xl font-bold text-blue-600">
                <span x-text="device.health?.cpu + '%'"></span> CPU
            </p>
        </div>
    </div>

    <!-- Device Details -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Device Information</h3>
            <dl class="space-y-3">
                <div>
                    <dt class="text-sm font-medium text-gray-600">Type</dt>
                    <dd class="text-gray-900" x-text="device.device_type"></dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-600">Vendor</dt>
                    <dd class="text-gray-900" x-text="device.vendor"></dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-600">Site</dt>
                    <dd class="text-gray-900" x-text="device.site_name"></dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-600">Cluster</dt>
                    <dd class="text-gray-900">
                        <span x-show="device.cluster_id">
                            <a :href="'/clusters/' + device.cluster_id" class="text-blue-600 hover:underline" x-text="device.cluster_name"></a>
                        </span>
                        <span x-show="!device.cluster_id">—</span>
                    </dd>
                </div>
            </dl>
        </div>

        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Configuration</h3>
            <dl class="space-y-3">
                <div>
                    <dt class="text-sm font-medium text-gray-600">Interfaces</dt>
                    <dd class="text-gray-900" x-text="device.interface_count || 0"></dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-600">Last Checked</dt>
                    <dd class="text-gray-900" x-text="formatDate(device.drift?.last_checked)"></dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-600">Routes</dt>
                    <dd class="text-gray-900" x-text="device.route_count || 0"></dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Ports/Interfaces -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Interfaces</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-gray-600">Name</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-600">Status</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-600">IP Address</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-600">Connections</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <template x-for="intf in device.interfaces || []" :key="intf.name">
                        <tr>
                            <td class="px-4 py-2 font-medium" x-text="intf.name"></td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-1 rounded text-xs"
                                      :class="intf.enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
                                      x-text="intf.enabled ? 'Up' : 'Down'"></span>
                            </td>
                            <td class="px-4 py-2" x-text="intf.ip_address || '—'"></td>
                            <td class="px-4 py-2">
                                <a href="#" class="text-blue-600 hover:underline" x-text="intf.connection_count || 0"></a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function deviceView() {
    return {
        device: {},

        async loadDevice() {
            const id = new URLSearchParams(window.location.search).get('id') || window.location.pathname.split('/')[2];
            try {
                const response = await fetch('/api/devices/' + id, {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    this.device = await response.json();
                }
            } catch (e) {
                console.error('Failed to load device:', e);
            }
        },

        formatDate(date) {
            if (!date) return '—';
            return new Date(date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    };
}
</script>
