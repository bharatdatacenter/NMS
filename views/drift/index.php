<?php $pageTitle = 'Drift Detection'; ?>

<div class="space-y-6" x-data="driftList()" x-init="loadDrifts()">
    <!-- Header -->
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-bold text-gray-900">Drift Detection</h1>
        <button @click="scanAll()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Scan All Devices
        </button>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg border border-gray-200 p-4 flex gap-4">
        <select x-model="filterStatus" class="px-4 py-2 border border-gray-300 rounded-lg">
            <option value="">All Status</option>
            <option value="open">Open</option>
            <option value="resolved">Resolved</option>
        </select>
        <input type="text" x-model="searchDevice" placeholder="Filter by device..." class="px-4 py-2 border border-gray-300 rounded-lg flex-1">
    </div>

    <!-- Drift List -->
    <div class="space-y-4">
        <template x-for="drift in filteredDrifts" :key="drift._id">
            <div class="bg-white rounded-lg border-l-4 border-yellow-400 p-6 hover:shadow-md transition">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <h3 class="text-lg font-bold text-gray-900" x-text="drift.device_name"></h3>
                        <p class="text-sm text-gray-600 mt-1">
                            <span x-text="drift.diffs.length"></span> differences detected
                        </p>
                        <div class="mt-3 space-y-1">
                            <template x-for="diff in drift.diffs.slice(0, 3)" :key="diff.identifier">
                                <p class="text-sm text-gray-600">
                                    <strong x-text="diff.section + ': '"></strong>
                                    <span x-text="diff.action"></span>
                                </p>
                            </template>
                        </div>
                    </div>
                    <div class="text-right ml-4">
                        <span :class="drift.status === 'open' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'"
                              class="px-3 py-1 rounded-full text-xs font-medium"
                              x-text="drift.status"></span>
                        <p class="text-xs text-gray-500 mt-2" x-text="formatDate(drift.detected_at)"></p>
                    </div>
                </div>

                <!-- Actions -->
                <template x-if="drift.status === 'open'">
                    <div class="mt-4 pt-4 border-t flex gap-3">
                        <button @click="resolve(drift._id, 'push')" class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                            Push (Enforce NMS)
                        </button>
                        <button @click="resolve(drift._id, 'pull')" class="px-3 py-1 bg-green-600 text-white text-sm rounded hover:bg-green-700">
                            Pull (Accept Device)
                        </button>
                        <button @click="resolve(drift._id, 'ignore')" class="px-3 py-1 bg-gray-600 text-white text-sm rounded hover:bg-gray-700">
                            Ignore
                        </button>
                        <a :href="'/drift/' + drift._id" class="px-3 py-1 bg-gray-200 text-gray-900 text-sm rounded hover:bg-gray-300">
                            Details
                        </a>
                    </div>
                </template>
            </div>
        </template>

        <template x-if="drifts.length === 0">
            <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                <p class="text-gray-600 text-lg">✓ No drift detected</p>
                <p class="text-gray-500 text-sm mt-2">All devices are in sync with NMS configuration</p>
            </div>
        </template>
    </div>
</div>

<script>
function driftList() {
    return {
        drifts: [],
        filterStatus: '',
        searchDevice: '',

        get filteredDrifts() {
            return this.drifts.filter(d => {
                const statusMatch = !this.filterStatus || d.status === this.filterStatus;
                const deviceMatch = !this.searchDevice || d.device_name.toLowerCase().includes(this.searchDevice.toLowerCase());
                return statusMatch && deviceMatch;
            });
        },

        async loadDrifts() {
            try {
                const response = await fetch('/api/drift', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.drifts = data.drifts || [];
                }
            } catch (e) {
                console.error('Failed to load drifts:', e);
            }
        },

        async scanAll() {
            try {
                const response = await fetch('/api/drift/scan-all', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    await this.loadDrifts();
                }
            } catch (e) {
                console.error('Scan failed:', e);
            }
        },

        async resolve(driftId, action) {
            try {
                const response = await fetch('/api/drift/' + driftId + '/resolve', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + localStorage.getItem('nms_token')
                    },
                    body: JSON.stringify({ action })
                });
                if (response.ok) {
                    await this.loadDrifts();
                }
            } catch (e) {
                console.error('Resolution failed:', e);
            }
        },

        formatDate(date) {
            if (!date) return '';
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
