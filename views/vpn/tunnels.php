<?php $pageTitle = 'VPN Tunnels'; ?>

<div class="space-y-6" x-data="vpnTunnels()" x-init="loadTunnels()">
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-bold text-gray-900">VPN Tunnels</h1>
        <a href="#" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">+ New Tunnel</a>
    </div>

    <!-- Tunnels List -->
    <div class="space-y-4">
        <template x-for="tunnel in tunnels" :key="tunnel._id">
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900" x-text="tunnel.name"></h3>
                        <p class="text-sm text-gray-600 mt-1">
                            <strong x-text="tunnel.tunnel_type"></strong>
                        </p>
                    </div>
                    <span :class="tunnel.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                          class="px-3 py-1 rounded-full text-xs font-medium"
                          x-text="tunnel.status"></span>
                </div>

                <!-- Details -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4 text-sm">
                    <div>
                        <p class="text-gray-600">Local Gateway</p>
                        <p class="font-mono text-xs mt-1" x-text="tunnel.local_endpoint"></p>
                    </div>
                    <div>
                        <p class="text-gray-600">Remote Gateway</p>
                        <p class="font-mono text-xs mt-1" x-text="tunnel.remote_endpoint"></p>
                    </div>
                    <div>
                        <p class="text-gray-600">Local Subnet</p>
                        <p class="font-mono text-xs mt-1" x-text="tunnel.local_subnet"></p>
                    </div>
                    <div>
                        <p class="text-gray-600">Remote Subnet</p>
                        <p class="font-mono text-xs mt-1" x-text="tunnel.remote_subnet"></p>
                    </div>
                </div>

                <!-- Traffic -->
                <div class="mb-4 p-3 bg-gray-50 rounded flex justify-between text-sm">
                    <div>
                        <p class="text-gray-600">Bytes In</p>
                        <p class="font-semibold text-gray-900" x-text="tunnel.bytes_in_mb + ' MB'"></p>
                    </div>
                    <div>
                        <p class="text-gray-600">Bytes Out</p>
                        <p class="font-semibold text-gray-900" x-text="tunnel.bytes_out_mb + ' MB'"></p>
                    </div>
                </div>

                <!-- Actions -->
                <a href="#" class="text-blue-600 hover:underline text-sm font-medium">Edit</a>
            </div>
        </template>
    </div>
</div>

<script>
function vpnTunnels() {
    return {
        tunnels: [],

        async loadTunnels() {
            try {
                const response = await fetch('/api/vpn/tunnels', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.tunnels = data.tunnels || [];
                }
            } catch (e) {
                console.error('Failed to load tunnels:', e);
            }
        }
    };
}
</script>
