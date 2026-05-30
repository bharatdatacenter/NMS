<?php $pageTitle = 'Sites'; ?>

<div class="space-y-6" x-data="sitesList()" x-init="loadSites()">
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-bold text-gray-900">Data Centers</h1>
        <a href="#" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">+ New Site</a>
    </div>

    <!-- Map or List -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Sites List -->
        <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
            <div class="space-y-4">
                <template x-for="site in sites" :key="site._id">
                    <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="font-bold text-gray-900" x-text="site.name"></h3>
                                <p class="text-sm text-gray-600 mt-1" x-text="site.location"></p>
                                <div class="mt-2 flex gap-4 text-sm">
                                    <span class="text-gray-600">
                                        <strong x-text="site.rack_count"></strong> Racks
                                    </span>
                                    <span class="text-gray-600">
                                        <strong x-text="site.device_count"></strong> Devices
                                    </span>
                                </div>
                            </div>
                            <a :href="'/sites/' + site._id" class="text-blue-600 hover:underline">View →</a>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Site Stats -->
        <div class="space-y-6">
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h3 class="font-bold text-gray-900 mb-4">Total Infrastructure</h3>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-gray-600">Sites</dt>
                        <dd class="text-2xl font-bold text-gray-900" x-text="sites.length"></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-600">Total Racks</dt>
                        <dd class="text-2xl font-bold text-gray-900" x-text="totalRacks"></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-600">Total Devices</dt>
                        <dd class="text-2xl font-bold text-gray-900" x-text="totalDevices"></dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>

<script>
function sitesList() {
    return {
        sites: [],

        get totalRacks() {
            return this.sites.reduce((sum, s) => sum + (s.rack_count || 0), 0);
        },

        get totalDevices() {
            return this.sites.reduce((sum, s) => sum + (s.device_count || 0), 0);
        },

        async loadSites() {
            try {
                const response = await fetch('/api/sites', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.sites = data.sites || [];
                }
            } catch (e) {
                console.error('Failed to load sites:', e);
            }
        }
    };
}
</script>
