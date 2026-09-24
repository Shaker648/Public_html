/* Source of robot3d.js. Rebuild (Node): npm i esbuild three@0.186.1 && npx esbuild dev/robot3d.src.js --bundle --minify --format=iife --target=es2019 --legal-comments=eof --outfile=robot3d.js */
/*
 * robot3d.js — the First 1 Car assistant as a real 3D robot (Three.js, bundled).
 *
 *   F1C3D.supported()                → can this device run it smoothly?
 *   F1C3D.create(el, { scale, big }) → a robot rendered into el (a canvas is added)
 *        .setMood('idle'|'happy'|'love'|'sleep')  .wave()  .party()  .enter()
 *        .flying(on)  .pause(on)  .destroy()
 *
 * The robot follows the pointer with its head, blinks, floats over a neon
 * ring, and its eyes / antenna / thrusters really glow (additive light sprites).
 * It watches the classes the widget already sets on #f1c-chat-bubble
 * (f1c-happy, f1c-love, f1c-sleep, f1c-wave, f1c-party, f1c-flying, f1c-enter)
 * so the rest of the widget doesn't need to know it's 3D.
 */
import * as THREE from 'three';
import { RoundedBoxGeometry } from 'three/examples/jsm/geometries/RoundedBoxGeometry.js';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';

const CYAN = new THREE.Color('#38bdf8'), PURPLE = new THREE.Color('#a855f7'), GREEN = new THREE.Color('#22c55e'), PINK = new THREE.Color('#f472b6');

/* ── soft round glow texture for the fake bloom ── */
let glowTex = null;
function glowTexture() {
    if (glowTex) return glowTex;
    const c = document.createElement('canvas'); c.width = c.height = 128;
    const g = c.getContext('2d'), grd = g.createRadialGradient(64, 64, 0, 64, 64, 64);
    grd.addColorStop(0, 'rgba(255,255,255,1)'); grd.addColorStop(.25, 'rgba(255,255,255,.55)');
    grd.addColorStop(.6, 'rgba(255,255,255,.12)'); grd.addColorStop(1, 'rgba(255,255,255,0)');
    g.fillStyle = grd; g.fillRect(0, 0, 128, 128);
    glowTex = new THREE.CanvasTexture(c); glowTex.colorSpace = THREE.SRGBColorSpace;
    return glowTex;
}
function glow(color, size, opacity = 1) {
    const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTexture(), color, transparent: true, opacity, blending: THREE.AdditiveBlending, depthWrite: false }));
    s.scale.set(size, size, 1);
    return s;
}
function heartShape() {
    const s = new THREE.Shape();
    s.moveTo(0, .25); s.bezierCurveTo(0, .25, -.05, .5, -.3, .5); s.bezierCurveTo(-.65, .5, -.65, .15, -.65, .15);
    s.bezierCurveTo(-.65, -.05, -.45, -.3, 0, -.55); s.bezierCurveTo(.45, -.3, .65, -.05, .65, .15);
    s.bezierCurveTo(.65, .15, .65, .5, .3, .5); s.bezierCurveTo(.05, .5, 0, .25, 0, .25);
    return s;
}

/* per-device switch: localStorage f1c3d = 'off' (always the flat robot) | 'force' (3D, skip the speed check) */
function pref() { try { return localStorage.getItem('f1c3d') || ''; } catch (e) { return ''; } }

export function supported() {
    try {
        if (pref() === 'off') return false;
        if (pref() === 'force') return !!document.createElement('canvas').getContext('webgl');
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) return false;
        if ((navigator.hardwareConcurrency || 4) < 4) return false;
        if (navigator.deviceMemory && navigator.deviceMemory < 3) return false;
        const c = document.createElement('canvas');
        const gl = c.getContext('webgl2') || c.getContext('webgl');
        if (!gl) return false;
        const ext = gl.getExtension('WEBGL_lose_context'); if (ext) ext.loseContext();
        return true;
    } catch (e) { return false; }
}

export function create(host, opts = {}) {
    const big = !!opts.big;
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: big ? 'high-performance' : 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, big ? 2 : 2));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.15;
    renderer.setClearColor(0x000000, 0);
    const canvas = renderer.domElement;
    canvas.style.cssText = 'width:100%;height:100%;display:block;pointer-events:none';
    host.appendChild(canvas);

    const scene = new THREE.Scene();
    const pmrem = new THREE.PMREMGenerator(renderer);
    scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
    const camera = new THREE.PerspectiveCamera(big ? 30 : 28, 1, 0.1, 50);
    camera.position.set(0, 0.2, big ? 8.6 : 9.4);
    camera.lookAt(0, -0.18, 0);

    /* lights: soft key + coloured rims that make the edges glow */
    scene.add(new THREE.HemisphereLight(0xdbeafe, 0x1e1b4b, 0.9));
    const key = new THREE.DirectionalLight(0xffffff, 2.2); key.position.set(2.5, 4, 5); scene.add(key);
    const rimC = new THREE.PointLight(CYAN, 18, 12); rimC.position.set(-3, 1.5, -2); scene.add(rimC);
    const rimP = new THREE.PointLight(PURPLE, 18, 12); rimP.position.set(3, 1, -2); scene.add(rimP);
    const under = new THREE.PointLight(CYAN, 6, 5); under.position.set(0, -1.8, 1); scene.add(under);

    /* materials */
    const pearl = new THREE.MeshPhysicalMaterial({ color: 0xf1f5fb, metalness: 0.25, roughness: 0.16, clearcoat: 1, clearcoatRoughness: 0.06, envMapIntensity: 1.25, sheen: 0.4, sheenColor: new THREE.Color('#c7d2fe') });
    const chrome = new THREE.MeshPhysicalMaterial({ color: 0xcbd5e1, metalness: 1, roughness: 0.12, envMapIntensity: 1.4 });
    const glass = new THREE.MeshPhysicalMaterial({ color: 0x050b1f, metalness: 0.1, roughness: 0.04, clearcoat: 1, clearcoatRoughness: 0.02, envMapIntensity: 2.2 });
    const dark = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.6, roughness: 0.35 });
    const eyeMat = new THREE.MeshBasicMaterial({ color: new THREE.Color('#7dd3fc'), toneMapped: false });
    const neon = (c) => new THREE.MeshBasicMaterial({ color: c, toneMapped: false });

    const root = new THREE.Group(); scene.add(root);        // position / float / flights
    const body = new THREE.Group(); root.add(body);          // turns toward the pointer a little
    const headPivot = new THREE.Group(); headPivot.position.y = 0.1; body.add(headPivot);   // turns more

    /* ── head ── */
    const head = new THREE.Mesh(new RoundedBoxGeometry(1.32, 1.04, 1.02, 6, 0.34), pearl);
    head.position.y = 0.52; headPivot.add(head);
    const visor = new THREE.Mesh(new RoundedBoxGeometry(1.08, 0.7, 0.14, 5, 0.22), glass);
    visor.position.set(0, 0.5, 0.46); headPivot.add(visor);
    // visor inner glow (subtle)
    const visorGlow = new THREE.Mesh(new THREE.PlaneGeometry(0.98, 0.6), new THREE.MeshBasicMaterial({ color: CYAN, transparent: true, opacity: 0.06, toneMapped: false, depthWrite: false }));
    visorGlow.position.set(0, 0.5, 0.535); headPivot.add(visorGlow);

    // eyes: normal capsules / happy arcs / hearts / sleepy lines
    const eyes = new THREE.Group(); eyes.position.set(0, 0.54, 0.545); headPivot.add(eyes);
    const mkEyeSet = (factory) => { const g = new THREE.Group(); [-0.23, 0.23].forEach(x => { const m = factory(); m.position.x = x; g.add(m); }); eyes.add(g); return g; };
    const eyeN = mkEyeSet(() => { const m = new THREE.Mesh(new THREE.CapsuleGeometry(0.075, 0.1, 6, 12), eyeMat); return m; });
    const eyeH = mkEyeSet(() => { const m = new THREE.Mesh(new THREE.TorusGeometry(0.085, 0.026, 8, 20, Math.PI), eyeMat); m.position.y = -0.03; return m; });
    const heartGeo = new THREE.ShapeGeometry(heartShape()); heartGeo.scale(0.2, 0.2, 0.2);
    const eyeL = mkEyeSet(() => { const m = new THREE.Mesh(heartGeo, neon(PINK)); return m; });
    const eyeS = mkEyeSet(() => { const m = new THREE.Mesh(new THREE.CapsuleGeometry(0.022, 0.12, 4, 8), neon(new THREE.Color('#64748b'))); m.rotation.z = Math.PI / 2; m.position.y = -0.03; return m; });
    const eyeGlows = [-0.23, 0.23].map(x => { const s = glow(CYAN, 0.55, 0.8); s.position.set(x, 0.54, 0.6); headPivot.add(s); return s; });
    // mouth: smile arc, bigger when happy
    const smile = new THREE.Mesh(new THREE.TorusGeometry(0.09, 0.018, 8, 20, Math.PI), eyeMat);
    smile.rotation.z = Math.PI; smile.position.set(0, 0.33, 0.545); headPivot.add(smile);
    const grin = new THREE.Mesh(new THREE.CircleGeometry(0.1, 24, 0, Math.PI), eyeMat);
    grin.rotation.z = Math.PI; grin.position.set(0, 0.345, 0.546); headPivot.add(grin);
    // cheeks
    const cheeks = [-0.38, 0.38].map(x => { const s = glow(PINK, 0.26, 0.45); s.position.set(x, 0.36, 0.56); headPivot.add(s); return s; });
    // ears
    [-1, 1].forEach(sd => {
        const ear = new THREE.Mesh(new THREE.CylinderGeometry(0.16, 0.16, 0.12, 24), chrome);
        ear.rotation.z = Math.PI / 2; ear.position.set(sd * 0.7, 0.52, 0); headPivot.add(ear);
        const ring = new THREE.Mesh(new THREE.TorusGeometry(0.1, 0.022, 8, 24), neon(CYAN));
        ring.rotation.y = Math.PI / 2; ring.position.set(sd * 0.765, 0.52, 0); headPivot.add(ring);
        const g = glow(CYAN, 0.45, 0.5); g.position.set(sd * 0.8, 0.52, 0); headPivot.add(g);
    });
    // antenna
    const antStick = new THREE.Mesh(new THREE.CylinderGeometry(0.022, 0.03, 0.34, 10), chrome);
    antStick.position.y = 1.2; headPivot.add(antStick);
    const antBallMat = neon(CYAN.clone());
    const antBall = new THREE.Mesh(new THREE.SphereGeometry(0.075, 20, 16), antBallMat);
    antBall.position.y = 1.4; headPivot.add(antBall);
    const antGlow = glow(CYAN, 0.7, 0.9); antGlow.position.y = 1.4; headPivot.add(antGlow);
    const orbiter = new THREE.Mesh(new THREE.SphereGeometry(0.025, 10, 8), neon(new THREE.Color('#fde047')));
    headPivot.add(orbiter);
    const orbGlow = glow(new THREE.Color('#fde047'), 0.2, 0.9); headPivot.add(orbGlow);

    /* ── neck + torso ── */
    const neck = new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.24, 0.14, 24), dark);
    neck.position.y = 0.02; body.add(neck);
    const neckRing = new THREE.Mesh(new THREE.TorusGeometry(0.23, 0.018, 8, 32), neon(CYAN));
    neckRing.rotation.x = Math.PI / 2; neckRing.position.y = 0.0; body.add(neckRing);
    const torso = new THREE.Mesh(new RoundedBoxGeometry(1.04, 0.82, 0.72, 6, 0.26), pearl);
    torso.position.y = -0.46; body.add(torso);
    // chest screen with the logo
    const chest = new THREE.Mesh(new RoundedBoxGeometry(0.72, 0.46, 0.06, 4, 0.08), glass);
    chest.position.set(0, -0.4, 0.36); body.add(chest);
    const logoMat = new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, toneMapped: false });
    new THREE.TextureLoader().load(opts.logo || 'icons/icon-192.png?v=4', (t) => { t.colorSpace = THREE.SRGBColorSpace; logoMat.map = t; logoMat.needsUpdate = true; });
    const logo = new THREE.Mesh(new THREE.PlaneGeometry(0.62, 0.4), logoMat);
    logo.position.set(0, -0.4, 0.395); body.add(logo);
    // neon belt that cycles colour
    const beltMat = neon(GREEN.clone());
    const belt = new THREE.Mesh(new RoundedBoxGeometry(0.8, 0.045, 0.02, 2, 0.02), beltMat);
    belt.position.set(0, -0.75, 0.365); body.add(belt);
    const beltGlow = glow(GREEN, 0.9, 0.35); beltGlow.scale.set(1.1, 0.25, 1); beltGlow.position.set(0, -0.75, 0.42); body.add(beltGlow);

    /* ── arms (shoulder pivots) ── */
    const mkArm = (sd) => {
        const pivot = new THREE.Group(); pivot.position.set(sd * 0.6, -0.2, 0); body.add(pivot);
        const joint = new THREE.Mesh(new THREE.SphereGeometry(0.12, 16, 12), chrome); pivot.add(joint);
        const arm = new THREE.Mesh(new THREE.CapsuleGeometry(0.1, 0.34, 6, 14), pearl); arm.position.y = -0.27; pivot.add(arm);
        const hand = new THREE.Mesh(new THREE.SphereGeometry(0.125, 18, 14), chrome); hand.position.y = -0.55; pivot.add(hand);
        pivot.rotation.z = sd * 0.2;
        return pivot;
    };
    const armL = mkArm(-1), armR = mkArm(1);

    /* ── thrusters with flames ── */
    const flames = [];
    [-0.24, 0.24].forEach(x => {
        const noz = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.13, 0.14, 18), chrome);
        noz.position.set(x, -0.93, 0); body.add(noz);
        const fl = new THREE.Mesh(new THREE.ConeGeometry(0.09, 0.42, 16, 1, true), new THREE.MeshBasicMaterial({ color: CYAN, transparent: true, opacity: 0.75, blending: THREE.AdditiveBlending, depthWrite: false, toneMapped: false }));
        fl.rotation.x = Math.PI; fl.position.set(x, -1.2, 0); body.add(fl);
        const fg = glow(PURPLE, 0.55, 0.8); fg.position.set(x, -1.18, 0.05); body.add(fg);
        flames.push({ fl, fg });
    });

    /* ── hover ring + shockwaves (don't turn with the body) ── */
    const ringY = -1.75;
    const ring = new THREE.Mesh(new THREE.TorusGeometry(0.62, 0.035, 12, 64), neon(CYAN));
    ring.rotation.x = Math.PI / 2; ring.position.y = ringY; scene.add(ring);
    const ring2 = new THREE.Mesh(new THREE.TorusGeometry(0.46, 0.018, 8, 64), neon(PURPLE));
    ring2.rotation.x = Math.PI / 2; ring2.position.y = ringY; scene.add(ring2);
    const ringGlow = glow(CYAN, 2.2, 0.45); ringGlow.scale.set(2.4, 0.7, 1); ringGlow.position.set(0, ringY, 0); scene.add(ringGlow);
    const shocks = [0, 1].map(i => {
        const m = new THREE.Mesh(new THREE.TorusGeometry(0.6, 0.03, 8, 64), new THREE.MeshBasicMaterial({ color: i ? PURPLE : CYAN, transparent: true, opacity: 0, toneMapped: false, depthWrite: false }));
        m.rotation.x = Math.PI / 2; m.position.y = ringY; scene.add(m); return { m, t: -1 };
    });

    /* ── particles (entrance trail / party sparks / idle dust) ── */
    const PN = big ? 160 : 90;
    const pGeo = new THREE.BufferGeometry();
    const pPos = new Float32Array(PN * 3), pCol = new Float32Array(PN * 3);
    const parts = [];
    const palette = [CYAN, PURPLE, GREEN, new THREE.Color('#fde047'), PINK];
    for (let i = 0; i < PN; i++) { parts.push({ life: 0, v: new THREE.Vector3() }); pPos[i * 3 + 1] = -99; }
    pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));
    pGeo.setAttribute('color', new THREE.BufferAttribute(pCol, 3));
    const pts = new THREE.Points(pGeo, new THREE.PointsMaterial({ size: big ? 0.09 : 0.11, map: glowTexture(), vertexColors: true, transparent: true, blending: THREE.AdditiveBlending, depthWrite: false, toneMapped: false }));
    scene.add(pts);
    let pNext = 0;
    function emit(pos, vel, life, color) {
        const p = parts[pNext]; pNext = (pNext + 1) % PN;
        p.life = life; p.max = life; p.v.copy(vel);
        pPos.set([pos.x, pos.y, pos.z], (pNext + PN - 1) % PN * 3);
        const c = color || palette[(Math.random() * palette.length) | 0];
        pCol.set([c.r, c.g, c.b], (pNext + PN - 1) % PN * 3);
    }
    function burst(n, at, speed) {
        for (let i = 0; i < n; i++) {
            const a = Math.random() * Math.PI * 2, e = (Math.random() - 0.3) * Math.PI;
            emit(at, new THREE.Vector3(Math.cos(a) * Math.cos(e), Math.sin(e) + 0.4, Math.sin(a) * Math.cos(e)).multiplyScalar(speed * (0.5 + Math.random())), 0.9 + Math.random() * 0.6);
        }
    }

    /* ── state ── */
    const S = { mood: 'idle', waveT: -1, partyT: -1, enterT: -1, flying: false, paused: false, blinkT: 0, nextBlink: 2.5, lookX: 0, lookY: 0, tx: 0, ty: 0, lastPointer: 0, idleGlance: 0 };
    let lastNow = performance.now(), elapsed = 0;
    const clock = { getDelta() { const n = performance.now(), d = (n - lastNow) / 1000; lastNow = n; elapsed += d; return d; }, get elapsedTime() { return elapsed; } };
    let raf = 0, dead = false;

    function setMood(m) { S.mood = m; }
    function wave() { S.waveT = 0; }
    function party() { S.partyT = 0; burst(big ? 60 : 36, new THREE.Vector3(0, 0.4, 0.3), 2.2); }
    function enter() { S.enterT = 0; }
    function flying(on) { S.flying = on; }

    /* pointer → where the head looks */
    function onPointer(e) {
        const r = host.getBoundingClientRect();
        const cx = r.left + r.width / 2, cy = r.top + r.height * 0.32;
        const dx = (e.clientX - cx) / Math.max(200, innerWidth * 0.5), dy = (e.clientY - cy) / Math.max(200, innerHeight * 0.5);
        S.tx = Math.max(-1, Math.min(1, dx)); S.ty = Math.max(-1, Math.min(1, dy));
        S.lastPointer = performance.now();
    }
    addEventListener('pointermove', onPointer, { passive: true });
    addEventListener('pointerdown', onPointer, { passive: true });

    function resize() {
        const w = host.clientWidth || 1, h = host.clientHeight || 1;
        renderer.setSize(w, h, false);
        camera.aspect = w / h; camera.updateProjectionMatrix();
    }
    const ro = new ResizeObserver(resize); ro.observe(host); resize();

    /* frame-rate guard: if the device struggles, tell the widget to fall back */
    let fpsFrames = 0, fpsStart = 0, fpsChecked = false;

    function frame() {
        if (dead) return;
        raf = requestAnimationFrame(frame);
        if (S.paused || document.hidden) { clock.getDelta(); return; }
        const dt = Math.min(0.05, clock.getDelta()), t = clock.elapsedTime;

        if (!fpsChecked) {
            if (!fpsStart) fpsStart = performance.now();
            fpsFrames++;
            const el = performance.now() - fpsStart;
            if (el > 2500) { fpsChecked = true; const fps = fpsFrames / (el / 1000); if (fps < 22 && opts.onSlow && pref() !== 'force') opts.onSlow(fps); }
        }

        const sleeping = S.mood === 'sleep';
        /* float */
        const bob = Math.sin(t * (sleeping ? 0.9 : 1.75)) * (sleeping ? 0.05 : 0.09);
        root.position.y = bob;
        root.rotation.z = Math.sin(t * 1.1) * 0.03 + (S.flying ? -0.18 : 0);

        /* look at the pointer (or glance around when nobody moves) */
        if (performance.now() - S.lastPointer > 4500) {
            S.idleGlance -= dt;
            if (S.idleGlance <= 0) { S.idleGlance = 1.6 + Math.random() * 2.2; S.tx = (Math.random() - 0.5) * 1.2; S.ty = (Math.random() - 0.6) * 0.6; }
        }
        S.lookX += (S.tx - S.lookX) * Math.min(1, dt * 5);
        S.lookY += (S.ty - S.lookY) * Math.min(1, dt * 5);
        headPivot.rotation.y = S.lookX * 0.42;
        headPivot.rotation.x = S.lookY * 0.22;
        body.rotation.y = S.lookX * 0.16 + Math.sin(t * 0.6) * 0.05;

        /* expressions */
        const happy = S.mood === 'happy' || S.waveT >= 0;
        const love = S.mood === 'love' || (S.partyT >= 0 && S.partyT < 1.6);
        eyeN.visible = !happy && !love && !sleeping;
        eyeH.visible = happy && !love && !sleeping;
        eyeL.visible = love && !sleeping;
        eyeS.visible = sleeping;
        grin.visible = happy || love; smile.visible = !grin.visible;
        eyeGlows.forEach(g => { g.material.opacity = sleeping ? 0.1 : love ? 0.9 : 0.8; g.material.color.copy(love ? PINK : CYAN); });
        cheeks.forEach(c => { c.material.opacity = happy || love ? 0.8 : 0.35; });
        if (love) eyeL.children.forEach((h, i) => h.scale.setScalar(1 + Math.sin(t * 9 + i) * 0.12));

        /* blink */
        S.blinkT += dt;
        if (S.blinkT > S.nextBlink) { S.blinkT = 0; S.nextBlink = 2.5 + Math.random() * 3; }
        const bl = S.blinkT < 0.14 ? Math.abs(Math.cos(S.blinkT / 0.14 * Math.PI)) : 1;
        eyeN.children.forEach(e => { e.scale.y = Math.max(0.08, bl); });

        /* antenna + belt colours cycle, orbiting spark */
        const cyc = (Math.sin(t * 1.4) + 1) / 2;
        antBallMat.color.copy(CYAN).lerp(PURPLE, cyc); antGlow.material.color.copy(antBallMat.color);
        antGlow.material.opacity = sleeping ? 0.25 : 0.75 + Math.sin(t * 3) * 0.2;
        beltMat.color.copy(GREEN).lerp(CYAN, (Math.sin(t * 2) + 1) / 2).lerp(PURPLE, (Math.sin(t * 1.3) + 1) / 4);
        orbiter.position.set(Math.cos(t * 2.6) * 0.2, 1.4 + Math.sin(t * 2.6) * 0.05, Math.sin(t * 2.6) * 0.2);
        orbGlow.position.copy(orbiter.position);

        /* arms: gentle swing, or a proper wave */
        armL.rotation.z = -0.2 - Math.sin(t * 1.75) * 0.06;
        armL.rotation.x = Math.sin(t * 1.75) * 0.08;
        if (S.waveT >= 0) {
            S.waveT += dt;
            const k = Math.min(1, S.waveT / 0.25) * (S.waveT > 1.9 ? Math.max(0, 1 - (S.waveT - 1.9) / 0.3) : 1);
            armR.rotation.z = 0.2 + k * (2.5 + Math.sin(S.waveT * 13) * 0.35);
            armR.rotation.x = -k * 0.25;
            if (S.waveT > 2.2) S.waveT = -1;
        } else { armR.rotation.z = 0.2 + Math.sin(t * 1.75 + 1.8) * 0.06; armR.rotation.x = 0; }

        /* party: full spin + jump */
        if (S.partyT >= 0) {
            S.partyT += dt;
            const k = Math.min(1, S.partyT / 0.9);
            body.rotation.y += k < 1 ? (1 - Math.cos(k * Math.PI)) / 2 * Math.PI * 2 : 0;
            root.position.y += Math.sin(Math.min(1, S.partyT / 0.9) * Math.PI) * 0.35;
            if (S.partyT > 2.2) S.partyT = -1;
        }

        /* entrance: drop in spinning from above with a particle trail, land, shockwave */
        if (S.enterT >= 0) {
            S.enterT += dt;
            const T = 1.25, k = Math.min(1, S.enterT / T);
            const ease = 1 - Math.pow(1 - k, 3);
            root.position.x = (1 - ease) * 2.2;
            root.position.y += (1 - ease) * 5.2 - (k > 0.82 ? Math.sin((k - 0.82) / 0.18 * Math.PI) * 0.12 : 0);
            root.rotation.y = (1 - ease) * Math.PI * 3;
            root.scale.setScalar(0.35 + 0.65 * ease);
            if (k < 0.9) for (let i = 0; i < 3; i++) emit(new THREE.Vector3(root.position.x + (Math.random() - 0.5) * 0.3, root.position.y - 0.6, 0), new THREE.Vector3((Math.random() - 0.5) * 0.4, 0.9 + Math.random(), (Math.random() - 0.5) * 0.4), 0.6 + Math.random() * 0.4);
            if (k >= 1 && S.enterT < T + dt * 1.5) {
                shocks.forEach((s, i) => { s.t = -i * 0.15; });
                burst(big ? 50 : 30, new THREE.Vector3(0, ringY + 0.1, 0), 1.6);
            }
            if (S.enterT > T + 0.1) { S.enterT = -1; root.position.x = 0; root.rotation.y = 0; root.scale.setScalar(1); if (opts.onLanded) opts.onLanded(); }
        }

        /* thrusters */
        const thrust = S.flying || (S.enterT >= 0) ? 1.7 : sleeping ? 0.35 : 1;
        flames.forEach((f, i) => {
            const fl = 0.85 + Math.sin(t * 38 + i * 2) * 0.12 + Math.random() * 0.06;
            f.fl.scale.set(1, fl * thrust, 1); f.fl.position.y = -1.2 - (fl * thrust - 1) * 0.2;
            f.fl.material.color.copy(CYAN).lerp(PURPLE, (Math.sin(t * 5 + i) + 1) / 2);
            f.fg.material.opacity = 0.55 * thrust;
        });
        if (!sleeping && Math.random() < (S.flying ? 0.6 : 0.12)) emit(new THREE.Vector3(root.position.x + (Math.random() < 0.5 ? -0.24 : 0.24), root.position.y - 1.35, 0), new THREE.Vector3((Math.random() - 0.5) * 0.3, -0.8 - Math.random() * 0.6, (Math.random() - 0.5) * 0.3), 0.5, Math.random() < 0.5 ? CYAN : PURPLE);

        /* ring pulse follows the float height (like a shadow) */
        const near = 1 - (root.position.y - bob * 0) * 0.15;
        ring.scale.setScalar(0.92 + Math.sin(t * 1.75) * 0.06); ring2.scale.setScalar(1 + Math.sin(t * 1.75 + 1) * 0.08);
        ring2.rotation.z = t * 0.6;
        ringGlow.material.opacity = (sleeping ? 0.2 : 0.4) * Math.max(0.3, near);
        under.intensity = sleeping ? 2 : 6 + Math.sin(t * 3) * 1.5;
        shocks.forEach(s => {
            if (s.t < -0.99 && s.t > -1.01) return;
            s.t += dt;
            if (s.t < 0) { s.m.material.opacity = 0; return; }
            const k = s.t / 0.9;
            s.m.scale.setScalar(0.4 + k * 2.6); s.m.material.opacity = Math.max(0, 1 - k);
            if (k >= 1) { s.t = -1; s.m.material.opacity = 0; }
        });

        /* particles */
        for (let i = 0; i < PN; i++) {
            const p = parts[i];
            if (p.life <= 0) { pPos[i * 3 + 1] = -99; continue; }
            p.life -= dt;
            p.v.y -= dt * 0.6;
            pPos[i * 3] += p.v.x * dt; pPos[i * 3 + 1] += p.v.y * dt; pPos[i * 3 + 2] += p.v.z * dt;
            const f = Math.max(0, p.life / p.max);
            pCol[i * 3] *= 0.985 + f * 0.015; pCol[i * 3 + 1] *= 0.985 + f * 0.015; pCol[i * 3 + 2] *= 0.985 + f * 0.015;
            if (p.life <= 0) pPos[i * 3 + 1] = -99;
        }
        pGeo.attributes.position.needsUpdate = true; pGeo.attributes.color.needsUpdate = true;

        renderer.render(scene, camera);
    }
    raf = requestAnimationFrame(frame);

    /* sparkle dust now and then in the big (welcome) view */
    let dustT = 0;
    if (big) dustT = setInterval(() => { if (!S.paused) emit(new THREE.Vector3((Math.random() - 0.5) * 3, -1.8, (Math.random() - 0.5) * 1.5), new THREE.Vector3(0, 0.6 + Math.random() * 0.6, 0), 2.5); }, 120);

    return {
        setMood, wave, party, enter, flying, burst: () => burst(30, new THREE.Vector3(0, 0.4, 0.3), 2),
        pause(on) { S.paused = !!on; },
        destroy() {
            dead = true; cancelAnimationFrame(raf); clearInterval(dustT); ro.disconnect();
            removeEventListener('pointermove', onPointer); removeEventListener('pointerdown', onPointer);
            renderer.dispose(); pmrem.dispose(); canvas.remove();
        },
    };
}

/* ═════════ hook into the widget: take over from the CSS robot when possible ═════════ */
function attach() {
    const bubble = document.getElementById('f1c-chat-bubble');
    const fail = () => dispatchEvent(new Event('f1c3d-fail'));
    if (!bubble || !supported()) return fail();
    const host = document.createElement('div');
    host.className = 'f1c-3d';
    host.setAttribute('aria-hidden', 'true');
    bubble.insertBefore(host, bubble.firstChild);
    let bot;
    try {
        bot = create(host, {
            onSlow: () => { try { bot.destroy(); } catch (e) {} host.remove(); bubble.classList.remove('f1c-has3d'); window.F1C3D.active = null; },
        });
    } catch (e) { host.remove(); return fail(); }
    bubble.classList.add('f1c-has3d');
    window.F1C3D.active = bot;
    dispatchEvent(new Event('f1c3d-ready'));

    /* mirror the widget's mood classes */
    const sync = () => {
        const c = bubble.classList;
        bot.setMood(c.contains('f1c-sleep') ? 'sleep' : c.contains('f1c-love') ? 'love' : (c.contains('f1c-happy') || bubble.matches(':hover')) ? 'happy' : 'idle');
        bot.flying(c.contains('f1c-flying'));
        bot.pause(c.contains('f1c-hidden') || c.contains('f1c-ascar'));
    };
    let wasWave = false, wasParty = false, wasEnter = false;
    new MutationObserver(() => {
        const c = bubble.classList;
        if (c.contains('f1c-wave') && !wasWave) bot.wave();
        if (c.contains('f1c-party') && !wasParty && !c.contains('f1c-ascar')) bot.party();
        if (c.contains('f1c-enter') && !wasEnter) bot.enter();
        wasWave = c.contains('f1c-wave'); wasParty = c.contains('f1c-party'); wasEnter = c.contains('f1c-enter');
        sync();
    }).observe(bubble, { attributes: true, attributeFilter: ['class'] });
    bubble.addEventListener('pointerenter', sync); bubble.addEventListener('pointerleave', sync);
    sync();
    if (bubble.classList.contains('f1c-enter')) bot.enter();
}

window.F1C3D = { supported, create, active: null, attach };
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach); else attach();
