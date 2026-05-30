<?php $pageTitle = 'Audit Logs'; ?>

<div class="space-y-6" x-data="auditLogs()" x-init="loadLogs()">
    <h1 class="text-3xl font-bold text-gray-900">Audit Logs</h1>

    <!-- Filters -->
    <div class="bg-white rounded-lg border border-gray-200 p-4 flex gap-4 flex-wrap">
        <select x-model="filterAction" class="px-4 py-2 border border-gray-300 rounded-lg">
            <option value="">All Actions</option>
            <option value="create">Create</option>
            <option value="update">Update</option>
            <option value="delete">Delete</option>
        </select>
        <select x-model="filterResource" class="px-4 py-2 border border-gray-300 rounded-lg">
            <option value="">All Resources</option>
            <option value="device">Device</option>
            <option value="policy">Policy</option>
            <option value="ipam">IPAM</option>
        </select>
        <input type="date" x-model="filterDate" class="px-4 py-2 border border-gray-300 rounded-lg">
    </div>

    <!-- Logs Table -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">Timestamp</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">User</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">Action</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">Resource</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">Details</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-600">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <template x-for="log in logs" :key="log._id">
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-gray-900" x-text="formatDate(log.created_at)"></td>
                        <td class="px-6 py-3 text-gray-900" x-text="log.user_name"></td>
                        <td class="px-6 py-3">
                            <span class="px-2 py-1 rounded text-xs font-medium"
                                  :class="log.action === 'create' ? 'bg-green-100 text-green-800' : log.action === 'delete' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'"
                                  x-text="log.action"></span>
                        </td>
                        <td class="px-6 py-3 text-gray-900" x-text="log.resource_type"></td>
                        <td class="px-6 py-3 text-gray-600 text-xs" x-text="log.resource_id"></td>
                        <td class="px-6 py-3">
                            <span class="px-2 py-1 rounded text-xs"
                                  :class="log.status === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                  x-text="log.status"></span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>

<script>
function auditLogs() {
    return {
        logs: [],
        filterAction: '',
        filterResource: '',
        filterDate: '',

        async loadLogs() {
            try {
                const response = await fetch('/api/audit', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.logs = data.logs || [];
                }
            } catch (e) {
                console.error('Failed to load audit logs:', e);
            }
        },

        formatDate(date) {
            return new Date(date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }
    };
}
</script>
