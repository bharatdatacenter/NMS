// Chart.js initialization helpers for NMS

// Utilization gauge (circular progress)
function initUtilizationGauge(canvasId, used, total, label = '') {
    const ctx = document.getElementById(canvasId).getContext('2d');
    const percent = (used / total * 100).toFixed(1);

    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Used', 'Available'],
            datasets: [{
                data: [used, total - used],
                backgroundColor: [
                    percent > 80 ? '#EF4444' : percent > 60 ? '#F59E0B' : '#10B981',
                    '#E5E7EB'
                ],
                borderColor: '#FFFFFF',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.label === 'Used') {
                                return `Used: ${used} / ${total} (${percent}%)`;
                            }
                            return `Available: ${total - used}`;
                        }
                    }
                }
            }
        }
    });
}

// Traffic chart (line chart over time)
function initTrafficChart(canvasId, ingressData, egressData, timestamps) {
    const ctx = document.getElementById(canvasId).getContext('2d');

    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: timestamps || [],
            datasets: [
                {
                    label: 'Ingress (Mbps)',
                    data: ingressData || [],
                    borderColor: '#3B82F6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true
                },
                {
                    label: 'Egress (Mbps)',
                    data: egressData || [],
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Mbps' }
                }
            }
        }
    });
}

// Health status chart (bar chart)
function initHealthChart(canvasId, deviceStats) {
    const ctx = document.getElementById(canvasId).getContext('2d');

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: deviceStats.map(d => d.name),
            datasets: [
                {
                    label: 'CPU %',
                    data: deviceStats.map(d => d.cpu),
                    backgroundColor: '#3B82F6'
                },
                {
                    label: 'Memory %',
                    data: deviceStats.map(d => d.memory),
                    backgroundColor: '#10B981'
                }
            ]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true, max: 100 }
            }
        }
    });
}

// Pie chart for IPAM distribution
function initIPAMDistributionChart(canvasId, ipv4Total, ipv6Total) {
    const ctx = document.getElementById(canvasId).getContext('2d');

    return new Chart(ctx, {
        type: 'pie',
        data: {
            labels: ['IPv4', 'IPv6'],
            datasets: [{
                data: [ipv4Total, ipv6Total],
                backgroundColor: ['#3B82F6', '#10B981'],
                borderColor: '#FFFFFF',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

window.NMSCharts = {
    initUtilizationGauge,
    initTrafficChart,
    initHealthChart,
    initIPAMDistributionChart
};
