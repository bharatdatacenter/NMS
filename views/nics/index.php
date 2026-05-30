<?php $pageTitle = 'Server NICs'; ?>

<div class="space-y-6" x-data="nicsList()" x-init="loadNICs()">
    <h1 class="text-3xl font-bold text-gray-900">Server Network Interfaces</h1>

    <!-- Filters -->
    <div class="bg-white rounded-lg border border-gray-200 p-4 flex gap-4">
        <input type="text" x-model="searchServer" placeholder="Filter by server..." class="px-4 py-2 border border-gray-300 rounded-lg flex-1">
        <select x-model="filterSite" class="px-4 py-2 border border-gray-300 rounded-lg">
            <option value="">All Sites</option>
        </select>
        <select x-model="filterVlan" class="px-4 py-2 border border-gray-300 rounded-lg">
            <option value="">All VLANs</option>
        </select>
    </div>

    <!-- NICs Table -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">Server</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">MAC Address</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">Switch Port</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">IP Assignments</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">VLAN</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <template x-for="nic in nics" :key="nic._id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 font-medium">
                            <a :href="'/nics/server/' + nic.server_id" class="text-blue-600 hover:underline" x-text="nic.server_id"></a>
                        </td>
                        <td class="px-6 py-3 font-mono text-xs" x-text="nic.mac_address"></td>
                        <td class="px-6 py-3 text-gray-600">
                            <a href="#" class="text-blue-600 hover:underline" x-text="nic.switch_port || '—'"></a>
                        </td>
                        <td class="px-6 py-3 text-xs">
                            <template x-for="assignment in (nic.ip_assignments || []).slice(0, 2)" :key="assignment.ip_address">
                                <div class="text-gray-900" x-text="assignment.ip_address"></div>
                            </template>
                            <template x-if="(nic.ip_assignments || []).length > 2">
                                <div class="text-gray-500">+<span x-text="nic.ip_assignments.length - 2"></span> more</div>
                            </template>
                        </td>
                        <td class="px-6 py-3">
                            <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded text-xs" x-text="nic.vlan_id"></span>
                        </td>
                        <td class="px-6 py-3">
                            <span :class="nic.status === 'connected' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
                                  class="px-2 py-1 rounded text-xs"
                                  x-text="nic.status || 'unknown'"></span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>

<script>
function nicsList() {
    return {
        nics: [],
        searchServer: '',
        filterSite: '',
        filterVlan: '',

        async loadNICs() {
            try {
                const response = await fetch('/api/nics', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.nics = data.nics || [];
                }
            } catch (e) {
                console.error('Failed to load NICs:', e);
            }
        }
    };
}
</script>
