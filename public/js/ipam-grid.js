// IP grid rendering for IPAM pool visualization
function initIPAMGrid(containerId, poolData, ipVersion = 'ipv4') {
    const container = document.getElementById(containerId);
    if (!container) return;

    // Parse CIDR to get total IPs
    const parts = poolData.cidr.split('/');
    const prefix = parseInt(parts[1]);
    const totalIPs = Math.pow(2, ipVersion === 'ipv4' ? 32 - prefix : 128 - prefix);

    // Create grid container
    const grid = document.createElement('div');
    grid.className = 'grid gap-1 p-4 bg-gray-50 rounded-lg overflow-auto max-h-96';
    grid.style.gridTemplateColumns = 'repeat(auto-fill, minmax(30px, 1fr))';

    // Color mapping: available, assigned, reserved, broadcast
    const colorMap = {
        'available': 'bg-green-100 border-green-300',
        'assigned': 'bg-blue-100 border-blue-300',
        'reserved': 'bg-yellow-100 border-yellow-300',
        'broadcast': 'bg-gray-200 border-gray-300'
    };

    // Build map of assigned/reserved IPs
    const assignedMap = {};
    (poolData.assignments || []).forEach(ip => {
        assignedMap[ip.ip_address] = 'assigned';
    });
    (poolData.reserved || []).forEach(ip => {
        assignedMap[ip.ip_address] = 'reserved';
    });

    // Create cells for each IP
    for (let i = 0; i < Math.min(totalIPs, 256); i++) {
        const cell = document.createElement('div');
        const status = assignedMap[`${poolData.network}+${i}`] || 'available';
        cell.className = `w-8 h-8 border rounded text-xs flex items-center justify-center cursor-pointer hover:opacity-80 ${colorMap[status]}`;
        cell.title = `IP ${i}: ${status}`;
        cell.textContent = i;
        grid.appendChild(cell);
    }

    container.appendChild(grid);

    // Legend
    const legend = document.createElement('div');
    legend.className = 'mt-4 flex flex-wrap gap-4 text-xs';
    legend.innerHTML = `
        <div class="flex items-center gap-2">
            <div class="w-4 h-4 bg-green-100 border border-green-300 rounded"></div>
            <span>Available</span>
        </div>
        <div class="flex items-center gap-2">
            <div class="w-4 h-4 bg-blue-100 border border-blue-300 rounded"></div>
            <span>Assigned</span>
        </div>
        <div class="flex items-center gap-2">
            <div class="w-4 h-4 bg-yellow-100 border border-yellow-300 rounded"></div>
            <span>Reserved</span>
        </div>
        <div class="flex items-center gap-2">
            <div class="w-4 h-4 bg-gray-200 border border-gray-300 rounded"></div>
            <span>Broadcast</span>
        </div>
    `;
    container.appendChild(legend);

    return grid;
}

// Interactive IP assignment
function assignIP(poolId, ipAddress) {
    return fetch(`/api/ipam/pools/${poolId}/assign`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + (Alpine.store('auth')?.token || '')
        },
        body: JSON.stringify({ ip_address: ipAddress })
    });
}

// Release IP
function releaseIP(poolId, ipAddress) {
    return fetch(`/api/ipam/pools/${poolId}/release`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + (Alpine.store('auth')?.token || '')
        },
        body: JSON.stringify({ ip_address: ipAddress })
    });
}

window.IPAMGrid = {
    initIPAMGrid,
    assignIP,
    releaseIP
};
