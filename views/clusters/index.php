<?php $pageTitle = 'Clusters'; ?>

<div class="space-y-6" x-data="clustersList()" x-init="loadClusters()">
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-bold text-gray-900">Device Clusters</h1>
        <a href="#" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">+ New Cluster</a>
    </div>

    <!-- Clusters Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <template x-for="cluster in clusters" :key="cluster._id">
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-2" x-text="cluster.name"></h3>
                <p class="text-sm text-gray-600 mb-4" x-text="cluster.type"></p>

                <!-- Members -->
                <div class="mb-4 space-y-2">
                    <p class="text-xs font-medium text-gray-600">Members (<span x-text="cluster.members.length"></span>)</p>
                    <template x-for="member in cluster.members" :key="member.id">
                        <div class="flex items-center justify-between text-sm">
                            <span x-text="member.name"></span>
                            <span :class="member.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                  class="px-2 py-1 rounded text-xs"
                                  x-text="member.status"></span>
                        </div>
                    </template>
                </div>

                <!-- Status -->
                <div class="mb-4 p-3 bg-blue-50 rounded">
                    <p class="text-xs font-medium text-gray-600">Cluster Status</p>
                    <p class="text-sm font-semibold text-blue-900 mt-1">
                        <span x-show="cluster.status === 'healthy'" class="text-green-600">✓ Healthy</span>
                        <span x-show="cluster.status !== 'healthy'" class="text-yellow-600">⚠️ Degraded</span>
                    </p>
                </div>

                <!-- Actions -->
                <a :href="'/clusters/' + cluster._id" class="text-blue-600 hover:underline text-sm font-medium">
                    View Details →
                </a>
            </div>
        </template>
    </div>
</div>

<script>
function clustersList() {
    return {
        clusters: [],

        async loadClusters() {
            try {
                const response = await fetch('/api/clusters', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.clusters = data.clusters || [];
                }
            } catch (e) {
                console.error('Failed to load clusters:', e);
            }
        }
    };
}
</script>
