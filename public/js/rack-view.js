// Rack elevation view with Three.js or SVG
function initRackView(containerId, rackData) {
    const container = document.getElementById(containerId);
    if (!container) return;

    // SVG-based rack diagram (simpler than Three.js for 2D)
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 400 1000');
    svg.setAttribute('class', 'w-full h-full');
    svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');

    // Draw rack frame
    const frame = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    frame.setAttribute('x', '50');
    frame.setAttribute('y', '20');
    frame.setAttribute('width', '300');
    frame.setAttribute('height', '950');
    frame.setAttribute('fill', 'none');
    frame.setAttribute('stroke', '#111827');
    frame.setAttribute('stroke-width', '2');
    svg.appendChild(frame);

    // Draw U-slots
    const slotHeight = (950 / (rackData.total_u || 42));
    for (let u = 0; u < (rackData.total_u || 42); u++) {
        const y = 20 + (u * slotHeight);

        // U-slot background
        const slot = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        slot.setAttribute('x', '50');
        slot.setAttribute('y', y.toString());
        slot.setAttribute('width', '300');
        slot.setAttribute('height', slotHeight.toString());
        slot.setAttribute('fill', u % 2 === 0 ? '#F9FAFB' : '#FFFFFF');
        slot.setAttribute('stroke', '#E5E7EB');
        slot.setAttribute('stroke-width', '1');
        svg.appendChild(slot);

        // U number
        const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        label.setAttribute('x', '35');
        label.setAttribute('y', (y + slotHeight / 2 + 5).toString());
        label.setAttribute('text-anchor', 'end');
        label.setAttribute('font-size', '12');
        label.setAttribute('fill', '#666');
        label.textContent = (rackData.total_u - u).toString();
        svg.appendChild(label);

        // Add device if present
        const device = (rackData.devices || []).find(d => d.u_start === u);
        if (device) {
            const deviceHeight = (device.u_height || 1) * slotHeight;
            const deviceRect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            deviceRect.setAttribute('x', '55');
            deviceRect.setAttribute('y', y.toString());
            deviceRect.setAttribute('width', '290');
            deviceRect.setAttribute('height', deviceHeight.toString());
            deviceRect.setAttribute('fill', device.status === 'online' ? '#D1FAE5' : '#FEE2E2');
            deviceRect.setAttribute('stroke', device.status === 'online' ? '#10B981' : '#EF4444');
            deviceRect.setAttribute('stroke-width', '2');
            deviceRect.setAttribute('class', 'cursor-pointer');
            deviceRect.setAttribute('data-device-id', device.id);

            // Device label
            const devLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            devLabel.setAttribute('x', '200');
            devLabel.setAttribute('y', (y + deviceHeight / 2 + 5).toString());
            devLabel.setAttribute('text-anchor', 'middle');
            devLabel.setAttribute('font-size', '11');
            devLabel.setAttribute('font-weight', 'bold');
            devLabel.setAttribute('fill', '#000');
            devLabel.textContent = device.name;

            svg.appendChild(deviceRect);
            svg.appendChild(devLabel);

            // Click handler
            deviceRect.addEventListener('click', () => {
                window.location.href = '/devices/' + device.id;
            });
        }
    }

    container.appendChild(svg);
    return svg;
}

// Three.js 3D rack view (optional, more advanced)
function initRackView3D(containerId, rackData) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(75, container.clientWidth / container.clientHeight, 0.1, 1000);
    const renderer = new THREE.WebGLRenderer({ antialias: true });

    renderer.setSize(container.clientWidth, container.clientHeight);
    container.appendChild(renderer.domElement);

    // Create rack frame
    const frameGeometry = new THREE.BoxGeometry(0.5, 2, 0.5);
    const frameMaterial = new THREE.MeshBasicMaterial({ color: 0x1f2937 });
    const frame = new THREE.Mesh(frameGeometry, frameMaterial);
    scene.add(frame);

    // Add devices as boxes
    (rackData.devices || []).forEach((device, idx) => {
        const geometry = new THREE.BoxGeometry(0.4, device.u_height * 0.05, 0.4);
        const material = new THREE.MeshBasicMaterial({
            color: device.status === 'online' ? 0x10b981 : 0xef4444
        });
        const mesh = new THREE.Mesh(geometry, material);
        mesh.position.y = idx * 0.1;
        scene.add(mesh);
    });

    camera.position.z = 2;

    function animate() {
        requestAnimationFrame(animate);
        renderer.render(scene, camera);
    }
    animate();
}

window.RackView = {
    initRackView,
    initRackView3D
};
