// Build the marker's DOM element once. Movement is updated imperatively
// afterwards via marker.setLngLat / marker.setRotation, so React never
// needs to re-render this.
//
// Icon design — navigation arrow (points north by default):
//   • With rotationAlignment:'map' + pitchAlignment:'map', Mapbox pins
//     the element to the map surface so it lies flat in 3D pitch view.
//   • marker.setRotation(bearing) then rotates it to face the direction
//     of travel. This works correctly at any pitch/bearing combination.
//   • The SVG has no inline CSS rotation — all rotation is through
//     Mapbox's own transform so the icon never diverges from the route.
export function createMarkerElement(color: string, onClick: () => void): HTMLDivElement {
    const wrapper = document.createElement('div');
    wrapper.style.width = '28px';
    wrapper.style.height = '28px';
    wrapper.style.cursor = 'pointer';
    wrapper.className = 'rider-marker-wrapper';

    // Pulse ring
    const ring = document.createElement('div');
    Object.assign(ring.style, {
        position: 'absolute',
        inset: '4px',
        borderRadius: '9999px',
        background: color,
        opacity: '0.2',
        animation: 'rider-ping 1.8s cubic-bezier(0,0,0.2,1) infinite',
    });
    wrapper.appendChild(ring);

    // SVG navigation arrow
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 44 44');
    Object.assign(svg.style, {
        position: 'relative',
        zIndex: '1',
        width: '28px',
        height: '28px',
        overflow: 'visible',
        filter: 'drop-shadow(0 2px 8px rgba(0,0,0,0.55))',
    });

    const circle = document.createElementNS(NS, 'circle');
    circle.setAttribute('cx', '22');
    circle.setAttribute('cy', '22');
    circle.setAttribute('r', '19');
    circle.setAttribute('fill', color);
    circle.setAttribute('stroke', 'white');
    circle.setAttribute('stroke-width', '2');

    const arrow = document.createElementNS(NS, 'path');
    arrow.setAttribute('d', 'M22,6 L33,34 L22,28 L11,34 Z');
    arrow.setAttribute('fill', 'white');
    arrow.setAttribute('opacity', '0.95');

    svg.appendChild(circle);
    svg.appendChild(arrow);
    wrapper.appendChild(svg);

    wrapper.addEventListener('click', (e) => {
        e.stopPropagation();
        onClick();
    });

    return wrapper;
}

// SOS emergency marker — large red pulsing marker with exclamation icon
export function createEmergencyMarkerElement(onClick: () => void): HTMLDivElement {
    const wrapper = document.createElement('div');
    wrapper.style.width = '40px';
    wrapper.style.height = '40px';
    wrapper.style.cursor = 'pointer';
    wrapper.className = 'emergency-marker-wrapper';

    // Outer slow-pulse ring
    const outerRing = document.createElement('div');
    Object.assign(outerRing.style, {
        position: 'absolute',
        inset: '-6px',
        borderRadius: '9999px',
        border: '2px solid #ef4444',
        opacity: '0.4',
        animation: 'sos-ping-slow 2s ease-in-out infinite',
    });
    wrapper.appendChild(outerRing);

    // Inner fast-pulse ring
    const innerRing = document.createElement('div');
    Object.assign(innerRing.style, {
        position: 'absolute',
        inset: '0px',
        borderRadius: '9999px',
        background: '#ef4444',
        opacity: '0.25',
        animation: 'sos-ping-fast 1.2s cubic-bezier(0,0,0.2,1) infinite',
    });
    wrapper.appendChild(innerRing);

    const NS = 'http://www.w3.org/2000/svg';

    // Main SVG — red circle with white exclamation mark
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 44 44');
    Object.assign(svg.style, {
        position: 'relative',
        zIndex: '1',
        width: '40px',
        height: '40px',
        overflow: 'visible',
        filter: 'drop-shadow(0 3px 10px rgba(239,68,68,0.7))',
    });

    const circle = document.createElementNS(NS, 'circle');
    circle.setAttribute('cx', '22');
    circle.setAttribute('cy', '22');
    circle.setAttribute('r', '20');
    circle.setAttribute('fill', '#ef4444');
    circle.setAttribute('stroke', 'white');
    circle.setAttribute('stroke-width', '2.5');

    // Exclamation body
    const excBody = document.createElementNS(NS, 'rect');
    excBody.setAttribute('x', '20');
    excBody.setAttribute('y', '10');
    excBody.setAttribute('width', '4');
    excBody.setAttribute('height', '16');
    excBody.setAttribute('rx', '2');
    excBody.setAttribute('fill', 'white');

    // Exclamation dot
    const excDot = document.createElementNS(NS, 'circle');
    excDot.setAttribute('cx', '22');
    excDot.setAttribute('cy', '32');
    excDot.setAttribute('r', '2.5');
    excDot.setAttribute('fill', 'white');

    svg.appendChild(circle);
    svg.appendChild(excBody);
    svg.appendChild(excDot);
    wrapper.appendChild(svg);

    wrapper.addEventListener('click', (e) => {
        e.stopPropagation();
        onClick();
    });

    return wrapper;
}
