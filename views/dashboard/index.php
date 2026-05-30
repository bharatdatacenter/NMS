<?php $pageTitle = 'Dashboard'; ?>

<!-- Page Header -->
<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">Dashboard</h1>
    <p class="text-gray-600">Infrastructure overview and quick actions</p>
</div>

<!-- Metrics Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
    <!-- Devices Online -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-gray-600 text-sm">Devices Online</p>
                <p class="text-3xl font-bold text-green-600" x-text="metrics.devices_online"></p>
            </div>
            <div class="text-green-600"><svg class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z"/></svg></div>
        </div>
    </div>

    <!-- Active Drift -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-gray-600 text-sm">Active Drift</p>
                <p class="text-3xl font-bold text-yellow-600" x-text="metrics.active_drift"></p>
            </div>
            <div class="text-yellow-600"><svg class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg></div>
        </div>
    </div>

    <!-- IP Utilization -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-gray-600 text-sm">IPAM Utilization</p>
                <p class="text-3xl font-bold text-blue-600" x-text="metrics.ip_utilization + '%'"></p>
            </div>
            <div class="text-blue-600"><svg class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z"/></svg></div>
        </div>
    </div>

</div>

<!-- Charts Row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- IPAM Utilization Chart -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4">IPAM Utilization by Pool</h3>
        <canvas id="ipamChart"></canvas>
    </div>

    <!-- Top Devices by Traffic -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Top Devices by Traffic</h3>
        <div class="space-y-3">
            <template x-for="device in metrics.top_devices" :key="device.id">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="font-medium text-gray-900" x-text="device.name"></p>
                        <p class="text-xs text-gray-500" x-text="device.ip"></p>
                    </div>
                    <p class="font-semibold text-blue-600" x-text="device.traffic"></p>
                </div>
            </template>
        </div>
    </div>
</div>

<!-- Drift Alerts -->
<div class="bg-white rounded-lg border border-gray-200 p-6 mb-8">
    <h3 class="text-lg font-bold text-gray-900 mb-4">Recent Drift Alerts</h3>
    <div class="space-y-3">
        <template x-if="metrics.recent_drifts && metrics.recent_drifts.length > 0">
            <template x-for="drift in metrics.recent_drifts.slice(0, 5)" :key="drift.id">
                <div class="flex justify-between items-center p-3 bg-yellow-50 rounded border border-yellow-200">
                    <div>
                        <p class="font-medium text-gray-900" x-text="drift.device_name"></p>
                        <p class="text-xs text-gray-600" x-text="'Detected: ' + drift.detected_at"></p>
                    </div>
                    <a :href="'/drift/' + drift.id" class="text-blue-600 hover:text-blue-900 font-medium text-sm">
                        View
                    </a>
                </div>
            </template>
        </template>
        <template x-if="!metrics.recent_drifts || metrics.recent_drifts.length === 0">
            <p class="text-gray-500 text-center py-4">No recent drift alerts</p>
        </template>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <a href="/devices" class="bg-blue-600 text-white rounded-lg p-6 hover:bg-blue-700 transition text-center">
        <p class="text-2xl mb-2">🖥️</p>
        <p class="font-semibold">Manage Devices</p>
    </a>
    <a href="/ipam" class="bg-green-600 text-white rounded-lg p-6 hover:bg-green-700 transition text-center">
        <p class="text-2xl mb-2">📍</p>
        <p class="font-semibold">IPAM Pools</p>
    </a>
    <a href="/drift" class="bg-yellow-600 text-white rounded-lg p-6 hover:bg-yellow-700 transition text-center">
        <p class="text-2xl mb-2">⚠️</p>
        <p class="font-semibold">Drift Detection</p>
    </a>
</div>

<script>
document.addEventListener('alpine:initialized', async () => {
    const dashboardData = {
        metrics: {
            devices_online: 0,
            active_drift: 0,
            ip_utilization: 0,
            top_devices: [],
            recent_drifts: []
        }
    };

    // Fetch dashboard metrics
    try {
        const response = await fetch('/api/monitoring/overview', {
            headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
        });
        if (response.ok) {
            const data = await response.json();
            dashboardData.metrics.devices_online = data.devices_online || 0;
        }
    } catch (e) {
        console.error('Failed to fetch dashboard data:', e);
    }

    // Initialize Alpine data
    Alpine.store('dashboard', dashboardData);

    // Initialize charts
    if (document.getElementById('ipamChart')) {
        NMSCharts.initIPAMDistributionChart('ipamChart', 60, 40);
    }
});
</script>
