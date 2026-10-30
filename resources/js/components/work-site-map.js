import { fetchWithTimeout } from './http';

const mapUrls = import.meta.glob('../../maps/*.geojson', { query: '?url', import: 'default', eager: true });

/**
 * Accueil map: the work sites of the company on a 3D map of the country they are in (chosen by the
 * server from the places found: one of the maps in resources/maps), one pin per place.
 * Pins are grouped on screen (40 px), regions can be zoomed, a pin opens the list of its work sites.
 * Data comes from the server (already placed); nothing is invented client side.
 */
const AMBER = 0xf2a104;
const BLUE = 0x4c8fbf;
const GREY = 0x8faab8;
const DEPTH = 0.06;
const CLUSTER_PX = 40;
const PIN_PX = 46;
const MODE_KEY = 'raccord_map_mode';
const MAX_POLLS = 40;
const REFRESH_MS = 60000;

const hex = (value) => `#${value.toString(16).padStart(6, '0')}`;

const el = (tag, className, text) => {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
};

const plural = (forms, count) => forms[new Intl.PluralRules(document.documentElement.lang || 'en').select(count)] ?? forms.other;

const fill = (template, values) => Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`:${key}`, String(value)), template);

/**
 * Like fill(), but returns nodes with every inserted value in bold (the figures of the subtitle).
 */
const fillBold = (template, values) => {
    const fragment = document.createDocumentFragment();

    template.split(/(:[a-z]+)/).forEach((part) => {
        const key = part.slice(1);

        if (part.startsWith(':') && key in values) {
            fragment.append(el('b', '', String(values[key])));
        } else if (part !== '') {
            fragment.append(part);
        }
    });

    return fragment;
};

const storedMode = () => {
    try {
        return localStorage.getItem(MODE_KEY) === 'all' ? 'all' : 'current';
    } catch {
        return 'current';
    }
};

const storeMode = (mode) => {
    try {
        localStorage.setItem(MODE_KEY, mode);
    } catch {
        // storage unavailable: the choice is simply not remembered
    }
};

const inRing = (ring, x, y) => {
    let inside = false;

    for (let a = 0, b = ring.length - 1; a < ring.length; b = a++) {
        const [xa, ya] = ring[a];
        const [xb, yb] = ring[b];

        if (ya > y !== yb > y && x < ((xb - xa) * (y - ya)) / (yb - ya) + xa) {
            inside = !inside;
        }
    }

    return inside;
};

export async function mountWorkSiteMap(root) {
    const labels = JSON.parse(root.dataset.labels || '{}');
    const url = root.dataset.url;
    const state = { mode: storedMode(), data: null, polls: 0, timer: null };

    root.replaceChildren();

    const toolbar = el('div', 'map__tools');
    const heading = root.closest('.home')?.querySelector('[data-map-subtitle]');
    const subtitle = heading ?? el('div', 'map__subtitle');
    const stage = el('div', 'map__stage');
    const tip = el('div', 'map__tip');
    const panel = el('div', 'map__panel');
    const regionChip = el('div', 'map__region');
    const legend = el('div', 'map__legend');
    const lists = el('div', 'map__lists');
    const message = el('div', 'map__message', labels.loading);

    const modeButtons = {
        current: el('button', '', labels.mode_current),
        all: el('button', '', labels.mode_all),
    };
    const zoomIn = el('button', 'map__zoom', '+');
    const zoomOut = el('button', 'map__zoom', '−');
    const recenter = el('button', '', `⟲ ${labels.recenter}`);

    [...Object.values(modeButtons), zoomIn, zoomOut, recenter].forEach((button) => {
        button.type = 'button';
        toolbar.append(button);
    });

    legend.append(...[[AMBER, labels.running], [BLUE, labels.confirmed], [GREY, labels.forecast]].map(([color, text]) => {
        const item = el('span', '', text);
        const dot = el('i', 'map__dot');
        dot.dataset.vars = `color:${hex(color)}`;
        item.prepend(dot);

        return item;
    }), el('span', 'map__hint', labels.hint));

    const top = el('div', 'map__top');
    top.append(el('h2', 'map__title', labels.title), toolbar);

    if (!heading) {
        top.append(subtitle);
    }

    const wrap = el('div', 'map__stagewrap');
    stage.append(message, tip, panel, regionChip);
    wrap.append(stage, legend);

    root.append(top);
    root.append(wrap, lists);

    let THREE;

    try {
        THREE = await import('three');
    } catch {
        message.textContent = labels.unavailable;

        return () => {};
    }

    let renderer;

    try {
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    } catch {
        message.textContent = labels.no_webgl;
        renderer = null;
    }

    const disposables = [];
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 200);
    const world = new THREE.Group();
    const pinsGroup = new THREE.Group();
    const view = { cx: 0, cz: 0, radius: 15, targetCx: 0, targetCz: 0, targetRadius: 15, fit: 15 };
    const regions = new Map();
    const regionMeshes = [];
    const raycaster = new THREE.Raycaster();
    const pointer = new THREE.Vector2();
    const textures = new Map();
    const materials = new Map();
    const startedAt = performance.now();
    let places = [];
    let allPlaces = [];
    let pins = [];
    let selectedRegion = null;
    let hoverPin = null;
    let dirty = true;
    let clusterSignature = '';
    let frame = null;
    let disposed = false;

    const shades = [0x17475a, 0x1a5166, 0x143f50];

    if (renderer) {
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
        stage.prepend(renderer.domElement);
        scene.add(new THREE.AmbientLight(0xffffff, 0.62 * Math.PI));
        const sun = new THREE.DirectionalLight(0xcfe8ff, 0.95 * Math.PI);
        sun.position.set(5, 11, 7);
        scene.add(sun, world, pinsGroup);
    }

    let frame2d = { lon0: 0, lat0: 0, k: 1 };
    let currentCountry = null;
    const project = (lon, lat) => [(lon - frame2d.lon0) * frame2d.k, lat - frame2d.lat0];

    const clearCountry = () => {
        [...world.children].forEach((child) => {
            world.remove(child);
            child.geometry?.dispose();
        });
        regionMeshes.length = 0;
        regions.clear();
        selectedRegion = null;
        regionChip.classList.remove('is-visible');
    };

    /**
     * Draws the regions of a country (the map the server picked from the places found).
     */
    const buildCountry = async (code) => {
        const url = mapUrls[`../../maps/${code}.geojson`] ?? mapUrls['../../maps/fr.geojson'];
        const geo = await fetchWithTimeout(url, {}, { kind: 'file' }).then((response) => response.json());

        clearCountry();
        currentCountry = code;

        const longitudes = [];
        const latitudes = [];
        const collect = (coordinates) => (Array.isArray(coordinates[0]) ? coordinates.forEach(collect) : (longitudes.push(coordinates[0]), latitudes.push(coordinates[1])));
        geo.features.forEach((feature) => collect(feature.geometry.coordinates));
        frame2d = { lon0: (Math.min(...longitudes) + Math.max(...longitudes)) / 2, lat0: (Math.min(...latitudes) + Math.max(...latitudes)) / 2, k: 1 };
        frame2d.k = Math.cos((frame2d.lat0 * Math.PI) / 180);

        geo.features.forEach((feature, index) => {
            const polygons = feature.geometry.type === 'Polygon' ? [feature.geometry.coordinates] : feature.geometry.coordinates;
            const material = new THREE.MeshStandardMaterial({ color: shades[index % 3], roughness: 0.72, metalness: 0.12, transparent: true, opacity: 0 });
            disposables.push(material);
            const region = { name: feature.properties.name, material, index, rings: [], box: { minX: 1e9, maxX: -1e9, minY: 1e9, maxY: -1e9 }, dim: false };
            regions.set(region.name, region);

            polygons.forEach((polygon) => {
                const toVectors = (ring) => ring.map(([lon, lat]) => new THREE.Vector2(...project(lon, lat)));
                const outer = toVectors(polygon[0]);
                region.rings.push(outer.map((v) => [v.x, v.y]));
                outer.forEach((v) => {
                    region.box.minX = Math.min(region.box.minX, v.x);
                    region.box.maxX = Math.max(region.box.maxX, v.x);
                    region.box.minY = Math.min(region.box.minY, v.y);
                    region.box.maxY = Math.max(region.box.maxY, v.y);
                });

                const shape = new THREE.Shape(outer);
                polygon.slice(1).forEach((hole) => shape.holes.push(new THREE.Path(toVectors(hole))));
                const geometry = new THREE.ExtrudeGeometry(shape, { depth: DEPTH, bevelEnabled: false });
                const mesh = new THREE.Mesh(geometry, material);
                mesh.rotation.x = -Math.PI / 2;
                mesh.userData.region = region.name;
                world.add(mesh);
                regionMeshes.push(mesh);

                const edges = new THREE.EdgesGeometry(geometry, 35);
                const lines = new THREE.LineSegments(edges, new THREE.LineBasicMaterial({ color: AMBER, transparent: true, opacity: 0.5 }));
                lines.rotation.x = -Math.PI / 2;
                world.add(lines);
                disposables.push(edges, lines.material);
            });
        });

        const all = [...regions.values()];
        const minX = Math.min(...all.map((r) => r.box.minX));
        const maxX = Math.max(...all.map((r) => r.box.maxX));
        const minY = Math.min(...all.map((r) => r.box.minY));
        const maxY = Math.max(...all.map((r) => r.box.maxY));
        view.fit = view.radius = view.targetRadius = Math.max(maxX - minX, maxY - minY) * 1.45;
        view.cx = view.targetCx = view.initialCx = (minX + maxX) / 2;
        view.cz = view.targetCz = view.initialCz = -(minY + maxY) / 2;
        dirty = true;
    };

    const worldPerPixel = () => (2 * view.radius * Math.tan((camera.fov * Math.PI) / 360)) / (renderer?.domElement.clientHeight || 1);

    const pinTexture = (color, count) => {
        const key = `${color}|${count}`;

        if (!textures.has(key)) {
            const canvas = document.createElement('canvas');
            canvas.width = 96;
            canvas.height = 128;
            const c = canvas.getContext('2d');
            c.shadowColor = 'rgba(0,0,0,.5)';
            c.shadowBlur = 6;
            c.shadowOffsetY = 3;
            c.beginPath();
            c.moveTo(48, 122);
            c.bezierCurveTo(48, 122, 10, 78, 10, 46);
            c.arc(48, 46, 38, Math.PI, 0);
            c.bezierCurveTo(86, 78, 48, 122, 48, 122);
            c.closePath();
            c.fillStyle = color;
            c.fill();
            c.shadowColor = 'transparent';
            c.lineWidth = 4;
            c.strokeStyle = '#fff';
            c.stroke();
            c.beginPath();
            c.arc(48, 46, count > 1 ? 26 : 14, 0, 7);
            c.fillStyle = '#fff';
            c.fill();

            if (count > 1) {
                c.fillStyle = '#12303F';
                c.font = `700 ${count > 99 ? 24 : count > 9 ? 29 : 35}px sans-serif`;
                c.textAlign = 'center';
                c.textBaseline = 'middle';
                c.fillText(String(count), 48, 49);
            }

            const texture = new THREE.CanvasTexture(canvas);
            textures.set(key, texture);
            disposables.push(texture);
        }

        return textures.get(key);
    };

    const spriteMaterial = (color, count) => {
        const key = `s${color}|${count}`;

        if (!materials.has(key)) {
            const material = new THREE.SpriteMaterial({ map: pinTexture(color, count), depthTest: false, depthWrite: false, transparent: true, sizeAttenuation: false });
            materials.set(key, material);
            disposables.push(material);
        }

        return materials.get(key);
    };

    const ringGeometry = renderer ? new THREE.RingGeometry(0.16, 0.25, 40) : null;
    const ringMaterial = (color) => {
        const key = `r${color}`;

        if (!materials.has(key)) {
            const material = new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.8, side: THREE.DoubleSide, depthTest: false });
            materials.set(key, material);
            disposables.push(material);
        }

        return materials.get(key);
    };

    const applyCamera = () => {
        camera.position.set(view.cx, view.radius, view.cz + view.radius * 0.28);
        camera.lookAt(view.cx, 0, view.cz);
        camera.updateMatrixWorld();
    };

    const inRegion = (region, x, y) => region.rings.some((ring) => inRing(ring, x, y));

    const filterPlaces = () => {
        const region = selectedRegion ? regions.get(selectedRegion) : null;
        places = region ? allPlaces.filter((place) => inRegion(region, place.x, -place.z)) : allPlaces;
        clusterSignature = '';
        dirty = true;

        regionChip.replaceChildren();
        regionChip.classList.toggle('is-visible', Boolean(region));

        if (region) {
            const count = places.reduce((total, place) => total + place.pin.items.length, 0);
            const back = el('button', '', labels.whole_map);
            back.type = 'button';
            back.addEventListener('click', () => selectRegion(null));
            regionChip.append(el('b', '', region.name), document.createTextNode(` · ${fill(plural(labels.sites_count, count), { n: count })}`), back);
        }
    };

    const setPlaces = (list) => {
        allPlaces = list.map((pin) => {
            const [x, y] = project(pin.lon, pin.lat);

            return { pin, x, z: -y };
        });
        filterPlaces();
    };

    const recluster = () => {
        if (!renderer) {
            return;
        }

        const width = renderer.domElement.clientWidth || 1;
        const height = renderer.domElement.clientHeight || 1;
        const vector = new THREE.Vector3();
        const screen = places.map((place) => {
            vector.set(place.x, DEPTH, place.z).project(camera);

            return { place, sx: (vector.x * 0.5 + 0.5) * width, sy: (-vector.y * 0.5 + 0.5) * height };
        });
        const ordered = screen.slice().sort((a, b) => b.place.pin.items.length - a.place.pin.items.length);
        const used = new Set();
        const clusters = [];

        ordered.forEach((a) => {
            if (used.has(a)) {
                return;
            }

            used.add(a);
            const members = [a];
            ordered.forEach((b) => {
                if (!used.has(b) && Math.hypot(a.sx - b.sx, a.sy - b.sy) < CLUSTER_PX) {
                    used.add(b);
                    members.push(b);
                }
            });
            clusters.push(members);
        });

        pins.forEach((pin) => {
            pinsGroup.remove(pin.object);
            pin.ring.material.dispose();
        });
        pins = [];

        clusters.forEach((members, index) => {
            const items = members.flatMap((m) => m.place.pin.items).sort((a, b) => a.starts.localeCompare(b.starts));
            const running = members.some((m) => m.place.pin.running);
            const confirmed = members.some((m) => m.place.pin.confirmed);
            let cx = 0;
            let cz = 0;
            let total = 0;
            members.forEach((m) => {
                const weight = m.place.pin.items.length;
                cx += m.place.x * weight;
                cz += m.place.z * weight;
                total += weight;
            });
            const names = members.slice().sort((a, b) => b.place.pin.items.length - a.place.pin.items.length).map((m) => m.place.pin.name);
            const color = running ? AMBER : confirmed ? BLUE : GREY;
            const object = new THREE.Group();
            object.position.set(cx / total, DEPTH + 0.02, cz / total);
            const sprite = new THREE.Sprite(spriteMaterial(hex(color), items.length));
            sprite.center.set(0.5, 0);
            sprite.renderOrder = 6;
            const ring = new THREE.Mesh(ringGeometry, ringMaterial(color).clone());
            ring.rotation.x = -Math.PI / 2;
            ring.renderOrder = 5;
            object.add(ring, sprite);
            const record = {
                object, sprite, ring, running, members: members.length, items,
                name: names.length > 1 ? `${names.slice(0, 5).join(' · ')}${names.length > 5 ? ' …' : ''}` : names[0],
                bornAt: startedAt + 250 + index * 40,
            };
            sprite.userData.pin = record;
            pinsGroup.add(object);
            pins.push(record);
        });
    };

    const resize = () => {
        if (!renderer) {
            return;
        }

        const width = stage.clientWidth || 1;
        const height = stage.clientHeight || 1;
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
        dirty = true;
    };

    const selectRegion = (name) => {
        selectedRegion = name;
        const region = name ? regions.get(name) : null;
        panel.classList.remove('is-visible');

        if (region) {
            view.targetCx = (region.box.minX + region.box.maxX) / 2;
            view.targetCz = -(region.box.minY + region.box.maxY) / 2;
            view.targetRadius = Math.max(region.box.maxX - region.box.minX, region.box.maxY - region.box.minY, 1) * 2.1;
        } else {
            reset();

            return;
        }

        regions.forEach((r) => {
            r.dim = Boolean(region) && r !== region;
        });
        filterPlaces();
    };

    const reset = () => {
        selectedRegion = null;
        view.targetCx = view.initialCx;
        view.targetCz = view.initialCz;
        view.targetRadius = view.fit;
        regions.forEach((r) => { r.dim = false; });
        panel.classList.remove('is-visible');
        filterPlaces();
    };

    const zoomBy = (factor) => {
        view.targetRadius = Math.min(Math.max(view.targetRadius * factor, 2.5), view.fit * 1.4);
        dirty = true;
    };

    const openPanel = (record) => {
        panel.replaceChildren();
        const close = el('button', 'map__close', '×');
        close.type = 'button';
        close.addEventListener('click', () => panel.classList.remove('is-visible'));
        panel.append(close, el('h4', '', `${record.name} · ${fill(plural(labels.sites_count, record.items.length), { n: record.items.length })}`));

        record.items.forEach((item) => {
            const row = el('button', 'map__item');
            row.type = 'button';
            const dot = el('i', 'map__dot');
            dot.dataset.vars = `color:${hex(item.status === 'confirmed' ? BLUE : GREY)}`;
            const title = el('span', 'map__item-title', item.label);
            title.prepend(dot);
            row.append(title, el('span', 'map__item-meta', item.meta));
            row.addEventListener('click', () => window.Livewire?.dispatchTo('work-sites.work-site-editor', 'work-site-open', { id: item.id }));
            panel.append(row);
        });

        panel.classList.add('is-visible');
        panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };

    const pick = (event) => {
        const box = renderer.domElement.getBoundingClientRect();
        pointer.set(((event.clientX - box.left) / box.width) * 2 - 1, -((event.clientY - box.top) / box.height) * 2 + 1);
        raycaster.setFromCamera(pointer, camera);
        const pinHit = raycaster.intersectObjects(pins.map((p) => p.sprite), false)[0];

        if (pinHit) {
            return { pin: pinHit.object.userData.pin };
        }

        const regionHit = raycaster.intersectObjects(regionMeshes, false)[0];

        return regionHit ? { region: regionHit.object.userData.region } : {};
    };

    const pointers = new Map();
    let dragging = false;
    let pinch = 0;
    let startX = 0;
    let startY = 0;

    const onDown = (event) => {
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        startX = event.clientX;
        startY = event.clientY;
        dragging = false;
        pinch = pointers.size === 2 ? Math.hypot(...[...pointers.values()].reduce((d, p, i, a) => (i ? [p.x - a[0].x, p.y - a[0].y] : d), [0, 0])) : 0;
    };

    const onMove = (event) => {
        const previous = pointers.get(event.pointerId);

        if (previous && pointers.size === 1 && Math.hypot(event.clientX - startX, event.clientY - startY) > 4) {
            dragging = true;
            stage.classList.add('is-dragging');
            const scale = worldPerPixel();
            view.targetCx = view.cx -= (event.clientX - previous.x) * scale;
            view.targetCz = view.cz -= (event.clientY - previous.y) * scale * 1.05;
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            dirty = true;

            return;
        }

        if (previous && pointers.size === 2) {
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            const [a, b] = [...pointers.values()];
            const distance = Math.hypot(a.x - b.x, a.y - b.y);

            if (pinch > 0 && distance > 0) {
                zoomBy(pinch / distance);
                view.radius = view.targetRadius;
            }

            pinch = distance;
            dragging = true;

            return;
        }

        if (!previous && renderer) {
            const hit = pick(event);
            const box = stage.getBoundingClientRect();
            stage.classList.toggle('is-pin', Boolean(hit.pin));
            hoverPin = hit.pin ?? null;
            dirty = true;
            tip.classList.toggle('is-visible', Boolean(hit.pin || hit.region));

            if (hit.pin || hit.region) {
                tip.textContent = hit.pin ? `${hit.pin.name} · ${fill(plural(labels.sites_count, hit.pin.items.length), { n: hit.pin.items.length })}` : hit.region;
                tip.dataset.vars = `x:${event.clientX - box.left}px;y:${event.clientY - box.top}px`;
            }
        }
    };

    const onUp = (event) => {
        if (!pointers.delete(event.pointerId)) {
            return;
        }

        stage.classList.remove('is-dragging');

        if (dragging || !renderer) {
            return;
        }

        const hit = pick(event);

        if (hit.pin) {
            if (hit.pin.members > 1) {
                view.targetCx = hit.pin.object.position.x;
                view.targetCz = hit.pin.object.position.z;
                view.targetRadius = Math.max(view.targetRadius * 0.4, 2.5);
                panel.classList.remove('is-visible');
            } else {
                openPanel(hit.pin);
            }
        } else if (hit.region) {
            selectedRegion === hit.region ? reset() : selectRegion(hit.region);
        } else {
            reset();
        }
    };

    const render = (now) => {
        frame = null;

        if (disposed) {
            return;
        }

        const delta = Math.abs(view.targetRadius - view.radius) + Math.abs(view.targetCx - view.cx) + Math.abs(view.targetCz - view.cz);

        if (delta > 0.001) {
            view.radius += (view.targetRadius - view.radius) * 0.18;
            view.cx += (view.targetCx - view.cx) * 0.18;
            view.cz += (view.targetCz - view.cz) * 0.18;
            dirty = true;
        }

        const intro = now - startedAt < 2600;
        const pulsing = pins.some((pin) => pin.running);

        if (dirty || intro || pulsing) {
            applyCamera();
            const signature = `${view.radius.toFixed(2)}|${view.cx.toFixed(2)}|${view.cz.toFixed(2)}|${renderer.domElement.clientWidth}|${places.length}`;

            if (signature !== clusterSignature) {
                clusterSignature = signature;
                recluster();
            }

            regions.forEach((region) => {
                const appear = Math.min(1, Math.max(0, (now - startedAt - region.index * 90) / 600));
                region.material.opacity = appear * (region.dim ? 0.35 : 1);
            });

            const scale = worldPerPixel();
            const height = renderer.domElement.clientHeight || 1;

            pins.forEach((pin) => {
                const age = (now - pin.bornAt) / 650;
                pin.object.visible = age >= 0;

                if (age < 0) {
                    return;
                }

                const eased = 1 - (1 - Math.min(1, age)) ** 3;
                const size = PIN_PX * (pin === hoverPin ? 1.2 : 1) * (0.25 + 0.75 * eased);
                pin.sprite.scale.set((size * 0.75) / height, size / height, 1);
                const radius = (19 * scale) / 0.25;

                if (pin.running) {
                    const phase = ((now - pin.bornAt) % 1500) / 1500;
                    pin.ring.scale.setScalar(radius * (1 + phase * 1.8));
                    pin.ring.material.opacity = 0.75 * (1 - phase);
                } else {
                    pin.ring.scale.setScalar(radius);
                    pin.ring.material.opacity = 0.45;
                }
            });

            renderer.render(scene, camera);
            dirty = false;
        }

        frame = requestAnimationFrame(render);
    };

    const draw = async () => {
        const data = state.data;
        subtitle.replaceChildren();
        lists.replaceChildren();

        if (!data) {
            return;
        }

        subtitle.append(
            fillBold(plural(state.mode === 'all' ? labels.works_all : labels.works_current, data.total), { n: data.total }),
            ' · ',
            fillBold(plural(labels.places, data.pins.length), { n: data.pins.length }),
        );

        if (data.pending > 0) {
            subtitle.append(' ', fillBold(labels.locating, { k: data.pending }));
        }
        message.textContent = data.total === 0 ? labels.none : '';
        Object.entries(modeButtons).forEach(([mode, button]) => button.classList.toggle('is-active', mode === state.mode));

        [[labels.unlocated, data.unlocated], [labels.off_map, data.offMap]].forEach(([title, list]) => {
            if (!list.length) {
                return;
            }

            const line = el('div', 'map__unlocated');
            line.append(el('b', '', title));
            list.forEach((entry) => {
                const button = el('button', '', entry.count > 1 ? `${entry.city} ×${entry.count}` : entry.city);
                button.type = 'button';
                button.addEventListener('click', () => window.Livewire?.dispatchTo('work-sites.work-site-editor', 'work-site-open', { id: entry.id }));
                line.append(' ', button);
            });
            lists.append(line);
        });

        if (renderer) {
            if (data.country !== currentCountry) {
                await buildCountry(data.country);
            }

            setPlaces(data.pins);
        } else if (data.pins.length) {
            const line = el('div', 'map__unlocated');
            line.append(el('b', '', labels.located));
            data.pins.forEach((pin) => pin.items.forEach((item) => line.append(' ', el('span', '', `${pin.name}: ${item.label}`))));
            lists.append(line);
        }
    };

    const load = async () => {
        clearTimeout(state.timer);

        try {
            const response = await fetchWithTimeout(`${url}?mode=${state.mode}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

            if (!response.ok) {
                throw new Error('map');
            }

            state.data = await response.json();
            await draw();

            if (state.data.pending > 0 && state.polls++ < MAX_POLLS && !disposed) {
                state.timer = setTimeout(load, 2500);
            }
        } catch {
            message.textContent = labels.unavailable;
        }
    };

    Object.entries(modeButtons).forEach(([mode, button]) => button.addEventListener('click', () => {
        state.mode = mode;
        state.polls = 0;
        storeMode(mode);
        load();
    }));
    zoomIn.addEventListener('click', () => zoomBy(0.7));
    zoomOut.addEventListener('click', () => zoomBy(1.4));
    recenter.addEventListener('click', reset);

    if (renderer) {
        const surface = renderer.domElement;
        surface.addEventListener('pointerdown', onDown);
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        surface.addEventListener('pointerleave', () => {
            tip.classList.remove('is-visible');
            hoverPin = null;
            dirty = true;
        });
        surface.addEventListener('wheel', (event) => {
            event.preventDefault();
            zoomBy(Math.exp(event.deltaY * 0.0015));
        }, { passive: false });
        surface.addEventListener('dblclick', reset);
        new ResizeObserver(resize).observe(stage);
        resize();
        applyCamera();
        frame = requestAnimationFrame(render);
    }

    const refresher = setInterval(() => {
        // Only when nobody is looking at a detail: no open panel and no zoomed region.
        if (document.visibilityState === 'visible' && !panel.classList.contains('is-visible') && !selectedRegion) {
            state.polls = 0;
            load();
        }
    }, REFRESH_MS);

    load();

    return () => {
        clearInterval(refresher);
        disposed = true;
        clearTimeout(state.timer);

        if (frame) {
            cancelAnimationFrame(frame);
        }

        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
        disposables.forEach((item) => item.dispose?.());
        renderer?.dispose();
    };
}

/**
 * Mounts every map container found in the page (only on wide screens) and tears it down when removed.
 */
export function initWorkSiteMaps() {
    const wide = window.matchMedia('(min-width: 961px)');
    const mounted = new Map();

    const scan = () => {
        mounted.forEach((dispose, element) => {
            if (!element.isConnected) {
                dispose();
                mounted.delete(element);
            }
        });

        if (!wide.matches) {
            return;
        }

        document.querySelectorAll('[data-work-site-map]').forEach((element) => {
            if (!mounted.has(element)) {
                mounted.set(element, () => {});
                mountWorkSiteMap(element).then((dispose) => mounted.set(element, dispose));
            }
        });
    };

    new MutationObserver(scan).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('DOMContentLoaded', scan);
    document.addEventListener('livewire:navigated', scan);
}
