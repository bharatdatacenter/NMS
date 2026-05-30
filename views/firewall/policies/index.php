<?php $pageTitle = 'Firewall Policies'; ?>

<div class="space-y-6" x-data="policiesList()" x-init="loadPolicies()">
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-bold text-gray-900">Firewall Policies</h1>
        <a href="#" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">+ New Policy</a>
    </div>

    <!-- Filter -->
    <div class="bg-white rounded-lg border border-gray-200 p-4 flex gap-4">
        <select x-model="filterVersion" class="px-4 py-2 border border-gray-300 rounded-lg">
            <option value="">All Versions</option>
            <option value="ipv4">IPv4</option>
            <option value="ipv6">IPv6</option>
        </select>
        <input type="text" placeholder="Search..." class="px-4 py-2 border border-gray-300 rounded-lg flex-1">
    </div>

    <!-- Policies Table -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Device</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">IP Version</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Rules</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <template x-for="policy in policies" :key="policy._id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 font-medium" x-text="policy.name"></td>
                        <td class="px-6 py-4 text-gray-600" x-text="policy.device_name"></td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded" x-show="policy.ip_version === 'ipv4'">IPv4</span>
                            <span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded" x-show="policy.ip_version === 'ipv6'">IPv6</span>
                        </td>
                        <td class="px-6 py-4 text-gray-900" x-text="policy.rules ? policy.rules.length : 0"></td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded" x-show="policy.enabled">Active</span>
                            <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded" x-show="!policy.enabled">Disabled</span>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <a :href="'/firewall/policies/' + policy._id + '/edit'" class="text-blue-600 hover:underline">Edit</a>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>

<script>
function policiesList() {
    return {
        policies: [],
        filterVersion: '',

        async loadPolicies() {
            try {
                const response = await fetch('/api/firewall/policies', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.policies = data.policies || [];
                }
            } catch (e) {
                console.error('Failed to load policies:', e);
            }
        }
    };
}
</script>
