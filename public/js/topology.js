// Cytoscape.js network topology visualization
function initTopology(containerId, nodes, edges, config = {}) {
    const defaultConfig = {
        layout: { name: 'cose-bilkent' },
        style: [
            {
                selector: 'node',
                style: {
                    'background-color': '#3B82F6',
                    'label': 'data(label)',
                    'text-opacity': 1,
                    'text-valign': 'center',
                    'text-halign': 'center',
                    'font-size': 12,
                    'width': 30,
                    'height': 30
                }
            },
            {
                selector: 'node[type="cluster"]',
                style: {
                    'background-color': '#EF4444',
                    'width': 40,
                    'height': 40
                }
            },
            {
                selector: 'node[status="offline"]',
                style: {
                    'background-color': '#9CA3AF'
                }
            },
            {
                selector: 'node[drift="drifted"]',
                style: {
                    'background-color': '#F59E0B'
                }
            },
            {
                selector: 'edge',
                style: {
                    'line-color': '#D1D5DB',
                    'width': 2,
                    'target-arrow-color': '#D1D5DB',
                    'target-arrow-shape': 'triangle'
                }
            },
            {
                selector: 'node:selected',
                style: {
                    'background-color': '#8B5CF6',
                    'line-color': '#8B5CF6',
                    'line-width': 3
                }
            }
        ],
        ...config
    };

    const cy = cytoscape({
        container: document.getElementById(containerId),
        elements: [...nodes, ...edges],
        style: defaultConfig.style,
        layout: defaultConfig.layout
    });

    // Handle node click for cable trace
    cy.on('tap', 'node', (event) => {
        const node = event.target;
        console.log('Node clicked:', node.id());
        // Trigger custom event or callback
        window.dispatchEvent(new CustomEvent('topology:nodeSelected', {
            detail: { nodeId: node.id() }
        }));
    });

    // Fit layout to view
    cy.fit();

    return cy;
}

// Topology layer toggle utility
function toggleTopologyLayer(cy, layerType) {
    if (layerType === 'clusters') {
        cy.elements('node[type="cluster"]').toggleClass('hidden');
    } else if (layerType === 'offline') {
        cy.elements('node[status="offline"]').toggleClass('hidden');
    }
}

// Export for use in views
window.TopologyViz = {
    initTopology,
    toggleTopologyLayer
};
