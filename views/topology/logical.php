<?php $pageTitle = 'Logical Topology'; ?>

<div class="space-y-6" x-data="logicalTopology()" x-init="loadTopology()">
    <div class="flex justify-between items-center">
        <h1 class="text-3xl font-bold text-gray-900">Logical Network Topology</h1>
        <div class="flex gap-3">
            <button @click="toggleLayer('clusters')" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Toggle Clusters
            </button>
            <button @click="toggleLayer('offline')" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Show Offline
            </button>
        </div>
    </div>

    <!-- Cytoscape Canvas -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <div id="topologyCanvas" class="w-full" style="height: 600px; border: 1px solid #e5e7eb; border-radius: 0.5rem;"></div>
    </div>

    <!-- Legend -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="font-bold text-gray-900 mb-4">Legend</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="flex items-center gap-2">
                <div class="w-6 h-6 bg-blue-500 rounded-full"></div>
                <span class="text-sm">Device (Online)</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-6 h-6 bg-gray-500 rounded-full"></div>
                <span class="text-sm">Device (Offline)</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-6 h-6 bg-yellow-500 rounded-full"></div>
                <span class="text-sm">Drifted Config</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-6 h-6 bg-red-500 rounded-full"></div>
                <span class="text-sm">Cluster</span>
            </div>
        </div>
    </div>

    <!-- Selected Node Details -->
    <template x-if="selectedNode">
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <h3 class="font-bold text-gray-900 mb-4">Device Details</h3>
            <dl class="grid grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm font-medium text-gray-600">Name</dt>
                    <dd class="text-gray-900" x-text="selectedNode.label"></dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-600">Status</dt>
                    <dd :class="selectedNode.status === 'online' ? 'text-green-600' : 'text-red-600'" x-text="selectedNode.status"></dd>
                </div>
            </dl>
        </div>
    </template>
</div>

<script>
function logicalTopology() {
    return {
        selectedNode: null,

        async loadTopology() {
            try {
                const response = await fetch('/api/topology/logical', {
                    headers: { 'Authorization': 'Bearer ' + localStorage.getItem('nms_token') }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.renderTopology(data.nodes, data.edges);
                }
            } catch (e) {
                console.error('Failed to load topology:', e);
            }
        },

        renderTopology(nodes, edges) {
            if (window.TopologyViz && window.TopologyViz.initTopology) {
                const cy = window.TopologyViz.initTopology('topologyCanvas', nodes, edges);

                // Handle node selection
                cy.on('tap', 'node', (evt) => {
                    const node = evt.target;
                    this.selectedNode = {
                        label: node.data('label'),
                        status: node.data('status')
                    };
                });
            }
        },

        toggleLayer(layer) {
            console.log('Toggle layer:', layer);
        }
    };
}
</script>
