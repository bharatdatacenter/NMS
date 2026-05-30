<?php $pageTitle = 'IPAM'; ?>

<div class="space-y-6" x-data="ipamView()" x-init="loadPools()">
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-bold text-gray-900">IPAM Pools</h1>
        <a href="#" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">+ New Pool</a>
    </div>

    <!-- Pool Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <template x-for="pool in pools" :key="pool._id">
            <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-lg transition">
                <h3 class="text-lg font-bold text-gray-900 mb-2" x-text="pool.name"></h3>
                <p class="text-sm text-gray-600 mb-4" x-text="pool.cidr"></p>

                <!-- Utilization -->
                <div class="mb-4">
                    <div class="flex justify-between text-xs text-gray-600 mb-2">
                        <span>Utilization</span>
                        <span x-text="pool.utilization + '%'"></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-blue-600 h-2 rounded-full transition"
                             :style="'width: ' + pool.utilization + '%'"></div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-3 gap-3 text-center mb-4">
                    <div>
                        <p class="text-sm font-semibold text-gray-900" x-text="pool.assigned"></p>
                        <p class="text-xs text-gray-500">Assigned</p>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900" x-text="pool.available"></p>
                        <p class="text-xs text-gray-500">Available</p>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900" x-text="pool.reserved"></p>
                        <p class="text-xs text-gray-500">Reserved</p>
                    </div>
                </div>

                <!-- IP Version Badge -->
                <div class="flex gap-2 mb-4">
                    <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded" x-show="pool.ip_version === 'ipv4'">IPv4</span>
                    <span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded" x-show="pool.ip_version === 'ipv6'">IPv6</span>
                </div>

                <!-- Action -->
                <a :href="'/ipam/pools/' + pool._id" class="text-blue-600 hover:underline text-sm font-medium">
                    View Details →
                </a>
            </div>
        </template>
    </div>
</div>

<script>
function ipamView() {
    return {
        pools: [],

        async loadPools() {
            try {
                const response = await fetch('/api/ipam/pools', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.pools = data.pools || [];
                }
            } catch (e) {
                console.error('Failed to load pools:', e);
            }
        }
    };
}
</script>
