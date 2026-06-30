import * as THREE from 'three';
import { gsap } from 'gsap';

// Simplex noise shared across shaders
const noiseGLSL = `
  vec3 mod289(vec3 x) { return x - floor(x * (1.0 / 289.0)) * 289.0; }
  vec4 mod289(vec4 x) { return x - floor(x * (1.0 / 289.0)) * 289.0; }
  vec4 permute(vec4 x) { return mod289(((x*34.0)+1.0)*x); }
  vec4 taylorInvSqrt(vec4 r) { return 1.79284291400159 - 0.85373472095314 * r; }
  float snoise(vec3 v) {
    const vec2 C = vec2(1.0/6.0, 1.0/3.0);
    const vec4 D = vec4(0.0, 0.5, 1.0, 2.0);
    vec3 i = floor(v + dot(v, C.yyy));
    vec3 x0 = v - i + dot(i, C.xxx);
    vec3 g = step(x0.yzx, x0.xyz);
    vec3 l = 1.0 - g;
    vec3 i1 = min(g.xyz, l.zxy);
    vec3 i2 = max(g.xyz, l.zxy);
    vec3 x1 = x0 - i1 + C.xxx;
    vec3 x2 = x0 - i2 + C.yyy;
    vec3 x3 = x0 - D.yyy;
    i = mod289(i);
    vec4 p = permute(permute(permute(
      i.z + vec4(0.0, i1.z, i2.z, 1.0))
      + i.y + vec4(0.0, i1.y, i2.y, 1.0))
      + i.x + vec4(0.0, i1.x, i2.x, 1.0));
    float n_ = 0.142857142857;
    vec3 ns = n_ * D.wyz - D.xzx;
    vec4 j = p - 49.0 * floor(p * ns.z * ns.z);
    vec4 x_ = floor(j * ns.z);
    vec4 y_ = floor(j - 7.0 * x_);
    vec4 x = x_ * ns.x + ns.yyyy;
    vec4 y = y_ * ns.x + ns.yyyy;
    vec4 h = 1.0 - abs(x) - abs(y);
    vec4 b0 = vec4(x.xy, y.xy);
    vec4 b1 = vec4(x.zw, y.zw);
    vec4 s0 = floor(b0)*2.0 + 1.0;
    vec4 s1 = floor(b1)*2.0 + 1.0;
    vec4 sh = -step(h, vec4(0.0));
    vec4 a0 = b0.xzyw + s0.xzyw*sh.xxyy;
    vec4 a1 = b1.xzyw + s1.xzyw*sh.zzww;
    vec3 p0 = vec3(a0.xy, h.x);
    vec3 p1 = vec3(a0.zw, h.y);
    vec3 p2 = vec3(a1.xy, h.z);
    vec3 p3 = vec3(a1.zw, h.w);
    vec4 norm = taylorInvSqrt(vec4(dot(p0,p0), dot(p1,p1), dot(p2,p2), dot(p3,p3)));
    p0 *= norm.x; p1 *= norm.y; p2 *= norm.z; p3 *= norm.w;
    vec4 m = max(0.6 - vec4(dot(x0,x0), dot(x1,x1), dot(x2,x2), dot(x3,x3)), 0.0);
    m = m * m;
    return 42.0 * dot(m*m, vec4(dot(p0,x0), dot(p1,x1), dot(p2,x2), dot(p3,x3)));
  }
`;

// Dark crystal vertex shader — low-poly distortion
const crystalVertexShader = `
  uniform float uTime;
  uniform float uDistortion;
  uniform vec2 uMouse;
  varying vec3 vNormal;
  varying vec3 vWorldPos;
  varying vec3 vViewDir;
  varying float vFaceBrightness;

  ${noiseGLSL}

  void main() {
    vec3 pos = position;

    // Slow organic crystal distortion
    float n1 = snoise(pos * 0.8 + uTime * 0.15) * uDistortion;
    float n2 = snoise(pos * 1.6 + uTime * 0.1 + 50.0) * uDistortion * 0.4;

    // Mouse-reactive bulge
    float mouseInfluence = length(uMouse) * 0.15;
    pos += normal * (n1 + n2 + mouseInfluence * 0.05);

    // Subtle pull toward mouse direction
    pos.x += uMouse.x * 0.15;
    pos.y += uMouse.y * 0.1;

    vNormal = normalize(normalMatrix * normal);
    vec4 worldPos = modelMatrix * vec4(pos, 1.0);
    vWorldPos = worldPos.xyz;
    vViewDir = normalize(cameraPosition - worldPos.xyz);

    // Per-face brightness variation for crystal facets
    vFaceBrightness = abs(snoise(normal * 3.0 + uTime * 0.05));

    gl_Position = projectionMatrix * viewMatrix * worldPos;
  }
`;

// Dark crystal fragment — dramatic lighting, chromatic aberration on edges
const crystalFragmentShader = `
  uniform float uTime;
  uniform vec2 uMouse;
  varying vec3 vNormal;
  varying vec3 vWorldPos;
  varying vec3 vViewDir;
  varying float vFaceBrightness;

  void main() {
    // View-dependent fresnel
    float fresnel = pow(1.0 - max(dot(vNormal, vViewDir), 0.0), 3.0);

    // Dramatic directional lights
    vec3 lightDir1 = normalize(vec3(0.5, 1.0, 0.8));
    vec3 lightDir2 = normalize(vec3(-0.8, -0.3, 0.5));
    vec3 lightDir3 = normalize(vec3(uMouse.x, uMouse.y, 0.6));

    float diff1 = max(dot(vNormal, lightDir1), 0.0);
    float diff2 = max(dot(vNormal, lightDir2), 0.0);
    float diff3 = max(dot(vNormal, lightDir3), 0.0);

    // Sharp specular highlights (crystal reflections)
    vec3 halfDir1 = normalize(lightDir1 + vViewDir);
    vec3 halfDir2 = normalize(lightDir2 + vViewDir);
    vec3 halfDir3 = normalize(lightDir3 + vViewDir);
    float spec1 = pow(max(dot(vNormal, halfDir1), 0.0), 80.0);
    float spec2 = pow(max(dot(vNormal, halfDir2), 0.0), 60.0);
    float spec3 = pow(max(dot(vNormal, halfDir3), 0.0), 40.0);

    // Dark base color
    vec3 baseColor = vec3(0.02, 0.02, 0.03);

    // Subtle face variation
    baseColor += vec3(0.01) * vFaceBrightness;

    // Ocean teal highlights + lime signal (Business Intuitive)
    vec3 highlight1 = vec3(0.086, 0.635, 0.682);  // teal-bright (#16A2AE)
    vec3 highlight2 = vec3(0.059, 0.435, 0.471);  // ocean teal (#0F6F78)
    vec3 highlight3 = vec3(0.9, 0.95, 1.0);        // cool white
    vec3 signal     = vec3(0.784, 1.0, 0.353);     // soft lime (#C8FF5A)

    // Chromatic aberration on edges — split RGB channels
    float edgeFactor = fresnel;
    vec3 chromaR = baseColor + highlight1 * diff1 * 0.3 + highlight3 * spec1 * 1.2;
    vec3 chromaG = baseColor + highlight2 * diff2 * 0.25 + highlight3 * spec2 * 0.8;
    vec3 chromaB = baseColor + highlight1 * diff3 * 0.2 + highlight3 * spec3 * 0.6;

    // Offset channels for chromatic aberration
    float rChannel = chromaR.r + edgeFactor * 0.15;
    float gChannel = chromaG.g + edgeFactor * 0.05;
    float bChannel = chromaB.b + edgeFactor * 0.2;

    vec3 color = vec3(rChannel, gChannel, bChannel);

    // Add specular kicks
    color += highlight3 * spec1 * 0.8;
    color += highlight1 * spec2 * 0.3;
    color += highlight2 * spec3 * 0.2;
    color += signal * spec1 * 0.10;

    // Fresnel rim glow (subtle teal)
    color += highlight1 * fresnel * 0.12;

    // Subtle environment reflection fake
    vec3 reflectDir = reflect(-vViewDir, vNormal);
    float envReflect = smoothstep(-0.2, 0.8, reflectDir.y);
    color += vec3(0.03, 0.06, 0.08) * envReflect * fresnel;

    gl_FragColor = vec4(color, 0.95);
  }
`;

// Inner glow shader — subtle core illumination
const innerGlowVertexShader = `
  uniform float uTime;
  uniform float uDistortion;
  uniform vec2 uMouse;
  varying vec3 vNormal;
  varying vec3 vViewDir;

  ${noiseGLSL}

  void main() {
    vec3 pos = position;
    float n1 = snoise(pos * 0.8 + uTime * 0.15) * uDistortion;
    float n2 = snoise(pos * 1.6 + uTime * 0.1 + 50.0) * uDistortion * 0.4;
    pos += normal * (n1 + n2);
    pos.x += uMouse.x * 0.15;
    pos.y += uMouse.y * 0.1;

    // Slightly smaller for inner glow
    pos *= 0.97;

    vNormal = normalize(normalMatrix * normal);
    vec4 worldPos = modelMatrix * vec4(pos, 1.0);
    vViewDir = normalize(cameraPosition - worldPos.xyz);
    gl_Position = projectionMatrix * viewMatrix * worldPos;
  }
`;

const innerGlowFragmentShader = `
  varying vec3 vNormal;
  varying vec3 vViewDir;

  void main() {
    float fresnel = pow(1.0 - max(dot(vNormal, vViewDir), 0.0), 2.0);
    float invertFresnel = 1.0 - fresnel;
    vec3 glowColor = vec3(0.0, 0.5, 0.4);
    float alpha = invertFresnel * 0.06;
    gl_FragColor = vec4(glowColor, alpha);
  }
`;

// Particle shaders — subtle teal dust
const particleVertexShader = `
  uniform float uTime;
  attribute float aScale;
  attribute float aOffset;
  varying float vAlpha;

  void main() {
    vec3 pos = position;
    float t = uTime * 0.2 + aOffset;
    pos.x += sin(t * 1.1) * 0.4;
    pos.y += cos(t * 0.7) * 0.4;
    pos.z += sin(t * 0.9) * 0.3;

    vec4 mvPosition = modelViewMatrix * vec4(pos, 1.0);
    gl_PointSize = aScale * (150.0 / -mvPosition.z);
    gl_Position = projectionMatrix * mvPosition;

    vAlpha = 0.2 + 0.5 * (sin(t * 0.8) * 0.5 + 0.5);
  }
`;

const particleFragmentShader = `
  varying float vAlpha;

  void main() {
    float dist = length(gl_PointCoord - vec2(0.5));
    if (dist > 0.5) discard;
    float alpha = smoothstep(0.5, 0.0, dist) * vAlpha * 0.25;
    gl_FragColor = vec4(0.0, 0.83, 0.67, alpha);
  }
`;

export class HeroScene {
  constructor() {
    this.canvas = document.getElementById('heroCanvas');
    this.mouse = { x: 0, y: 0, targetX: 0, targetY: 0 };
    this.scrollProgress = 0;
    this.init();
  }

  init() {
    this.scene = new THREE.Scene();

    this.camera = new THREE.PerspectiveCamera(
      45,
      window.innerWidth / window.innerHeight,
      0.1,
      100
    );
    this.camera.position.z = 4.5;

    this.renderer = new THREE.WebGLRenderer({
      canvas: this.canvas,
      antialias: true,
      alpha: true,
    });
    this.renderer.setSize(window.innerWidth, window.innerHeight);
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    this.renderer.setClearColor(0x000000, 0);
    this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
    this.renderer.toneMappingExposure = 1.2;

    this.createCrystal();
    this.createParticles();
    this.addListeners();
    this.animate();
  }

  createCrystal() {
    // Low-poly for faceted crystal look (detail=2)
    const geometry = new THREE.IcosahedronGeometry(1.4, 2);
    // Compute flat normals for hard crystal edges
    geometry.computeVertexNormals();

    this.uniforms = {
      uTime: { value: 0 },
      uDistortion: { value: 0.35 },
      uMouse: { value: new THREE.Vector2(0, 0) },
    };

    // Main crystal — dark reflective surface
    const crystalMaterial = new THREE.ShaderMaterial({
      vertexShader: crystalVertexShader,
      fragmentShader: crystalFragmentShader,
      uniforms: this.uniforms,
      transparent: true,
      side: THREE.FrontSide,
    });

    this.crystal = new THREE.Mesh(geometry, crystalMaterial);
    this.scene.add(this.crystal);

    // Inner glow mesh
    const innerGlowMaterial = new THREE.ShaderMaterial({
      vertexShader: innerGlowVertexShader,
      fragmentShader: innerGlowFragmentShader,
      uniforms: this.uniforms,
      transparent: true,
      side: THREE.BackSide,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    });

    this.innerGlow = new THREE.Mesh(geometry.clone(), innerGlowMaterial);
    this.scene.add(this.innerGlow);

    // Intro animation
    this.crystal.scale.set(0, 0, 0);
    this.innerGlow.scale.set(0, 0, 0);

    gsap.to(this.crystal.scale, {
      x: 1, y: 1, z: 1,
      duration: 2.5,
      ease: 'elastic.out(1, 0.4)',
      delay: 0.3,
    });
    gsap.to(this.innerGlow.scale, {
      x: 1, y: 1, z: 1,
      duration: 2.5,
      ease: 'elastic.out(1, 0.4)',
      delay: 0.3,
    });
  }

  createParticles() {
    const count = 300;
    const positions = new Float32Array(count * 3);
    const scales = new Float32Array(count);
    const offsets = new Float32Array(count);

    for (let i = 0; i < count; i++) {
      const theta = Math.random() * Math.PI * 2;
      const phi = Math.acos(2 * Math.random() - 1);
      const r = 2.2 + Math.random() * 3.5;

      positions[i * 3] = r * Math.sin(phi) * Math.cos(theta);
      positions[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
      positions[i * 3 + 2] = r * Math.cos(phi);

      scales[i] = Math.random() * 1.5 + 0.3;
      offsets[i] = Math.random() * Math.PI * 2;
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('aScale', new THREE.BufferAttribute(scales, 1));
    geometry.setAttribute('aOffset', new THREE.BufferAttribute(offsets, 1));

    const material = new THREE.ShaderMaterial({
      vertexShader: particleVertexShader,
      fragmentShader: particleFragmentShader,
      uniforms: { uTime: this.uniforms.uTime },
      transparent: true,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    });

    this.particles = new THREE.Points(geometry, material);
    this.scene.add(this.particles);
  }

  addListeners() {
    window.addEventListener('mousemove', (e) => {
      this.mouse.targetX = (e.clientX / window.innerWidth) * 2 - 1;
      this.mouse.targetY = -(e.clientY / window.innerHeight) * 2 + 1;
    });

    window.addEventListener('resize', () => {
      this.camera.aspect = window.innerWidth / window.innerHeight;
      this.camera.updateProjectionMatrix();
      this.renderer.setSize(window.innerWidth, window.innerHeight);
    });
  }

  setScrollProgress(progress) {
    this.scrollProgress = progress;
  }

  animate() {
    requestAnimationFrame(() => this.animate());

    const time = performance.now() * 0.001;
    this.uniforms.uTime.value = time;

    // Smooth mouse
    this.mouse.x += (this.mouse.targetX - this.mouse.x) * 0.04;
    this.mouse.y += (this.mouse.targetY - this.mouse.y) * 0.04;
    this.uniforms.uMouse.value.set(this.mouse.x, this.mouse.y);

    // Slow crystal rotation
    if (this.crystal) {
      this.crystal.rotation.x = time * 0.08 + this.mouse.y * 0.2;
      this.crystal.rotation.y = time * 0.12 + this.mouse.x * 0.2;
      this.innerGlow.rotation.copy(this.crystal.rotation);
    }

    // Particles drift
    if (this.particles) {
      this.particles.rotation.y = time * 0.03;
      this.particles.rotation.x = time * 0.02;
    }

    // Scroll-based camera offset
    const scrollOffset = this.scrollProgress;
    this.camera.position.y = -scrollOffset * 1.5;
    this.camera.position.z = 4.5 + scrollOffset * 1.0;

    this.renderer.render(this.scene, this.camera);
  }

  destroy() {
    this.renderer.dispose();
  }
}
