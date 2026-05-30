<?php $pageTitle = 'Devices'; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-bold text-gray-900">Devices</h1>
        <a href="/devices/create" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            + New Device
        </a>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg border border-gray-200 p-4 flex gap-4">
        <input type="text" placeholder="Search devices..." class="px-4 py-2 border border-gray-300 rounded-lg flex-1">
        <select class="px-4 py-2 border border-gray-300 rounded-lg">
            <option value="">All Status</option>
            <option value="online">Online</option>
            <option value="offline">Offline</option>
        </select>
        <select class="px-4 py-2 border border-gray-300 rounded-lg">
            <option value="">All Types</option>
            <option value="router">Router</option>
            <option value="switch">Switch</option>
            <option value="firewall">Firewall</option>
        </select>
    </div>

    <!-- Devices Table -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden" x-data="deviceList()" x-init="loadDevices()">
        <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP Address</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Drift</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <template x-for="device in devices" :key="device._id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 font-medium text-gray-900" x-text="device.name"></td>
                        <td class="px-6 py-4 text-gray-600" x-text="device.ip_address"></td>
                        <td class="px-6 py-4">
                            <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-xs" x-text="device.device_type"></span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-3 py-1 rounded-full text-xs"
                                  :class="device.status === 'online' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                  x-text="device.status"></span>
                        </td>
                        <td class="px-6 py-4">
                            <template x-if="device.drift && device.drift.status === 'drifted'">
                                <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs">⚠️ Drifted</span>
                            </template>
                            <template x-if="!device.drift || device.drift.status === 'clean'">
                                <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs">✓ Clean</span>
                            </template>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <a :href="'/devices/' + device._id" class="text-blue-600 hover:underline mr-3">View</a>
                            <a :href="'/devices/' + device._id + '/edit'" class="text-blue-600 hover:underline">Edit</a>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>

<script>
function deviceList() {
    return {
        devices: [],

        async loadDevices() {
            try {
                const response = await fetch('/api/devices', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.devices = data.devices || [];
                }
            } catch (e) {
                console.error('Failed to load devices:', e);
            }
        }
    };
}
</script>
