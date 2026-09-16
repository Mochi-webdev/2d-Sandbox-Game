

let world = new Uint8Array(WORLD_WIDTH * WORLD_HEIGHT);
let wallWorld = new Uint8Array(WORLD_WIDTH * WORLD_HEIGHT);
let blockDamageMap = new Float32Array(WORLD_WIDTH * WORLD_HEIGHT);
let worldSeed = Math.random() * 10000;
let chestData = {};
let openChestKey = null;

const BIOME = {
    OCEAN: 0,
    BEACH: 1,
    DESERT: 2,
    PLAINS: 3,
    FOREST: 4,
    TAIGA: 5,
    SNOW: 6,
    MOUNTAIN: 7,
    HIGHLAND: 8
};

// ---------- Noise helpers ----------
function fade(t) { return t * t * t * (t * (t * 6 - 15) + 10); }
function lerp(a, b, t) { return a + (b - a) * t; }

function valueNoise2D(x, y, seed) {
    const x0 = Math.floor(x), y0 = Math.floor(y);
    const fx = fade(x - x0), fy = fade(y - y0);
    const n00 = pseudoNoise(x0, y0, seed);
    const n10 = pseudoNoise(x0 + 1, y0, seed);
    const n01 = pseudoNoise(x0, y0 + 1, seed);
    const n11 = pseudoNoise(x0 + 1, y0 + 1, seed);
    return lerp(lerp(n00, n10, fx), lerp(n01, n11, fx), fy);
}

/** Fractal Brownian Motion – smooth multi-octave noise */
function fbm(x, y, seed, octaves, lacunarity, gain) {
    octaves = octaves || 4;
    lacunarity = lacunarity || 2.0;
    gain = gain || 0.5;
    let amp = 1, freq = 1, sum = 0, norm = 0;
    for (let i = 0; i < octaves; i++) {
        sum += amp * valueNoise2D(x * freq, y * freq, seed + i * 31);
        norm += amp;
        amp *= gain;
        freq *= lacunarity;
    }
    return sum / norm;
}

function ridgeNoise(x, y, seed) {
    const n = fbm(x, y, seed, 4, 2.0, 0.5);
    return 1 - Math.abs(n * 2 - 1);
}

// ---------- Biome map (smooth, continental) ----------
function biomeClimate(x, seed) {
    // Temperature: large scale bands with local variation
    const temperature = fbm(x * 0.0045, 0.5, seed + 11, 5, 2.1, 0.5);
    // Moisture
    const moisture = fbm(x * 0.0055, 1.5, seed + 29, 4, 2.0, 0.55);
    // Elevation tendency (for mountains)
    const elevation = ridgeNoise(x * 0.006, 2.0, seed + 47);
    return { temperature, moisture, elevation };
}

function getBiomeAt(x, seed) {
    const { temperature: t, moisture: m, elevation: e } = biomeClimate(x, seed);

    // High elevation → mountains / highland regardless of temp
    if (e > 0.72) return BIOME.MOUNTAIN;
    if (e > 0.58 && t < 0.55) return BIOME.HIGHLAND;

    // Cold
    if (t < 0.28) {
        if (m > 0.45) return BIOME.TAIGA;
        return BIOME.SNOW;
    }
    // Cool
    if (t < 0.42) {
        if (m > 0.5) return BIOME.FOREST;
        if (m > 0.3) return BIOME.TAIGA;
        return BIOME.PLAINS;
    }
    // Temperate
    if (t < 0.62) {
        if (m > 0.58) return BIOME.FOREST;
        if (m < 0.28) return BIOME.DESERT;
        return BIOME.PLAINS;
    }
    // Hot
    if (m < 0.35) return BIOME.DESERT;
    if (m > 0.6) return BIOME.FOREST;
    return BIOME.PLAINS;
}

/** Blend surface heights across biome borders so transitions aren't cliffs */
function surfaceHeightAt(x, seed) {
    // Sample neighboring biomes and blend
    let heightSum = 0;
    let weightSum = 0;
    for (let dx = -6; dx <= 6; dx++) {
        const sx = clamp(x + dx, 0, WORLD_WIDTH - 1);
        const biome = getBiomeAt(sx, seed);
        const w = 1 / (1 + dx * dx * 0.35);
        heightSum += rawSurfaceHeight(sx, seed, biome) * w;
        weightSum += w;
    }
    return Math.round(heightSum / weightSum);
}

function rawSurfaceHeight(x, seed, biome) {
    const cont = fbm(x * 0.012, 0, seed + 3, 4, 2.0, 0.5); // continental
    const detail = fbm(x * 0.045, 0, seed + 7, 3, 2.2, 0.45);
    const hills = fbm(x * 0.09, 0, seed + 13, 2, 2.0, 0.5);

    let h = 38;
    h += (cont - 0.5) * 16;
    h += (detail - 0.5) * 8;
    h += (hills - 0.5) * 4;

    switch (biome) {
        case BIOME.OCEAN:
            h = 28 + (cont - 0.5) * 4;
            break;
        case BIOME.BEACH:
            h = 32 + (detail - 0.5) * 3;
            break;
        case BIOME.DESERT:
            // Dunes
            h += Math.sin(x * 0.11 + seed) * 3;
            h += (fbm(x * 0.07, 0, seed + 90, 2, 2, 0.5) - 0.5) * 5;
            h -= 1;
            break;
        case BIOME.PLAINS:
            h += (detail - 0.5) * 3;
            break;
        case BIOME.FOREST:
            h += (detail - 0.5) * 5;
            h += 1;
            break;
        case BIOME.TAIGA:
            h += (detail - 0.5) * 6;
            h += 2;
            break;
        case BIOME.SNOW:
            h += (detail - 0.5) * 5;
            h += ridgeNoise(x * 0.04, 0, seed + 55) * 4;
            h += 3;
            break;
        case BIOME.HIGHLAND:
            h += ridgeNoise(x * 0.035, 0, seed + 60) * 14;
            h += 6;
            break;
        case BIOME.MOUNTAIN:
            h += ridgeNoise(x * 0.028, 0, seed + 66) * 28;
            h += fbm(x * 0.08, 0, seed + 70, 3, 2.1, 0.5) * 6;
            h += 10;
            break;
    }
    return clamp(Math.round(h), 16, WORLD_HEIGHT - 18);
}

// ---------- Vegetation ----------
function generateTree(startX, startY, biome) {
    let trunkH, leafR, leafType;
    if (biome === BIOME.FOREST) {
        trunkH = 4 + Math.floor(Math.random() * 4);
        leafR = 2 + (Math.random() < 0.4 ? 1 : 0);
    } else if (biome === BIOME.TAIGA || biome === BIOME.SNOW) {
        // Tall thin pines
        trunkH = 5 + Math.floor(Math.random() * 4);
        leafR = 1;
    } else {
        trunkH = 3 + Math.floor(Math.random() * 3);
        leafR = 1 + (Math.random() < 0.3 ? 1 : 0);
    }

    for (let i = 0; i < trunkH; i++) {
        const ty = startY - i;
        if (ty >= 0 && ty < WORLD_HEIGHT) world[ty * WORLD_WIDTH + startX] = BLOCKS.WOOD;
    }

    const leafTop = startY - trunkH;
    if (biome === BIOME.TAIGA || biome === BIOME.SNOW) {
        // Conifer layers
        for (let layer = 0; layer < 4; layer++) {
            const ly = leafTop + layer;
            const r = layer === 0 ? 0 : (layer === 3 ? 1 : 2);
            for (let lx = -r; lx <= r; lx++) {
                const x = startX + lx, y = ly;
                if (x < 0 || x >= WORLD_WIDTH || y < 0 || y >= WORLD_HEIGHT) continue;
                const idx = y * WORLD_WIDTH + x;
                if (world[idx] === BLOCKS.AIR) world[idx] = BLOCKS.LEAVES;
            }
        }
    } else {
        for (let ly = -leafR; ly <= leafR; ly++) {
            for (let lx = -leafR; lx <= leafR; lx++) {
                if (Math.abs(lx) + Math.abs(ly) > leafR + 1) continue;
                if (Math.abs(lx) === leafR && Math.abs(ly) === leafR) continue;
                const x = startX + lx, y = leafTop + ly;
                if (x < 0 || x >= WORLD_WIDTH || y < 0 || y >= WORLD_HEIGHT) continue;
                const idx = y * WORLD_WIDTH + x;
                if (world[idx] === BLOCKS.AIR) world[idx] = BLOCKS.LEAVES;
            }
        }
    }
}

function generateCactus(startX, startY) {
    const h = 2 + Math.floor(Math.random() * 3);
    for (let i = 0; i < h; i++) {
        const ty = startY - i;
        if (ty >= 0) world[ty * WORLD_WIDTH + startX] = BLOCKS.WOOD; // reuse wood as cactus body (visual via context)
    }
}

// ---------- Ores ----------
function carveOreBlob(cx, cy, type, size) {
    for (let i = 0; i < size * 4; i++) {
        const dx = Math.floor((Math.random() - 0.5) * size * 1.8);
        const dy = Math.floor((Math.random() - 0.5) * size * 1.5);
        const x = cx + dx, y = cy + dy;
        if (x < 1 || x >= WORLD_WIDTH - 1 || y < 1 || y >= WORLD_HEIGHT - 1) continue;
        const idx = y * WORLD_WIDTH + x;
        const b = world[idx];
        if (b === BLOCKS.STONE || b === BLOCKS.DEEP_STONE || b === BLOCKS.MOSSY_STONE) {
            world[idx] = type;
        }
    }
}

function scatterOreVeins(seed) {
    const veinTypes = [
        { type: BLOCKS.COAL, minDepth: 4, maxDepth: 55, size: [3, 7], chance: 0.009 },
        { type: BLOCKS.COPPER, minDepth: 8, maxDepth: 50, size: [2, 5], chance: 0.007 },
        { type: BLOCKS.IRON, minDepth: 14, maxDepth: 70, size: [2, 5], chance: 0.0055 },
        { type: BLOCKS.GOLD, minDepth: 32, maxDepth: 90, size: [2, 4], chance: 0.0028 },
        { type: BLOCKS.CRYSTAL, minDepth: 48, maxDepth: 99, size: [2, 3], chance: 0.0016 }
    ];
    for (let x = 2; x < WORLD_WIDTH - 2; x++) {
        const surfaceH = surfaceHeightAt(x, seed);
        for (let y = surfaceH + 3; y < WORLD_HEIGHT - 2; y++) {
            const index = y * WORLD_WIDTH + x;
            const b = world[index];
            if (b !== BLOCKS.STONE && b !== BLOCKS.DEEP_STONE && b !== BLOCKS.MOSSY_STONE) continue;
            const depth = y - surfaceH;
            for (const vein of veinTypes) {
                if (depth < vein.minDepth || depth > vein.maxDepth) continue;
                const roll = pseudoNoise(x * 0.41, y * 0.53, seed + vein.type * 77);
                if (roll < vein.chance) {
                    const size = vein.size[0] + Math.floor(Math.random() * (vein.size[1] - vein.size[0] + 1));
                    carveOreBlob(x, y, vein.type, size);
                    break;
                }
            }
        }
    }
}

// ---------- Connected cave system (NOT cheese) ----------
function carveDisc(cx, cy, radius, blockType) {
    const r2 = radius * radius;
    for (let dy = -radius; dy <= radius; dy++) {
        for (let dx = -radius; dx <= radius; dx++) {
            if (dx * dx + dy * dy > r2) continue;
            const x = cx + dx, y = cy + dy;
            if (x < 1 || x >= WORLD_WIDTH - 1 || y < 2 || y >= WORLD_HEIGHT - 2) continue;
            world[y * WORLD_WIDTH + x] = blockType;
        }
    }
}

function carveEllipsoid(cx, cy, rx, ry, blockType) {
    for (let dy = -ry; dy <= ry; dy++) {
        for (let dx = -rx; dx <= rx; dx++) {
            if ((dx * dx) / (rx * rx) + (dy * dy) / (ry * ry) > 1) continue;
            const x = cx + dx, y = cy + dy;
            if (x < 1 || x >= WORLD_WIDTH - 1 || y < 2 || y >= WORLD_HEIGHT - 2) continue;
            world[y * WORLD_WIDTH + x] = blockType;
        }
    }
}

/** Drunkard-walk / worm tunnel that stays underground */
function carveWorm(seed, startX, startY, length, baseRadius) {
    let x = startX, y = startY;
    let angle = Math.random() * Math.PI * 2;
    for (let step = 0; step < length; step++) {
        // Prefer horizontal drift, gentle vertical
        angle += (Math.random() - 0.5) * 0.45;
        // Bias away from surface
        const surfaceH = surfaceHeightAt(Math.floor(x), seed);
        if (y < surfaceH + 8) angle = Math.PI * 0.5 + (Math.random() - 0.5) * 0.4;
        if (y > WORLD_HEIGHT - 8) angle = -Math.PI * 0.5 + (Math.random() - 0.5) * 0.4;

        x += Math.cos(angle) * 1.2;
        y += Math.sin(angle) * 0.75;

        const ix = Math.floor(x), iy = Math.floor(y);
        if (ix < 2 || ix >= WORLD_WIDTH - 2 || iy < 3 || iy >= WORLD_HEIGHT - 3) break;

        // Radius pulses for natural variation
        const rad = baseRadius + (Math.sin(step * 0.15) > 0.6 ? 1 : 0) + (Math.random() < 0.08 ? 1 : 0);
        carveDisc(ix, iy, rad, BLOCKS.AIR);

        // Occasional side pocket
        if (Math.random() < 0.04) {
            const px = ix + Math.floor((Math.random() - 0.5) * 6);
            const py = iy + Math.floor((Math.random() - 0.5) * 4);
            carveEllipsoid(px, py, 2 + Math.floor(Math.random() * 2), 2, BLOCKS.AIR);
        }
    }
}

function generateCaveNetwork(seed) {
    // 1) Long horizontal-ish worm tunnels (main arteries)
    const mainTunnels = 7 + Math.floor(Math.random() * 5);
    for (let i = 0; i < mainTunnels; i++) {
        const sx = 15 + Math.floor(Math.random() * (WORLD_WIDTH - 30));
        const surfaceH = surfaceHeightAt(sx, seed);
        const sy = surfaceH + 12 + Math.floor(Math.random() * Math.min(40, WORLD_HEIGHT - surfaceH - 20));
        const len = 60 + Math.floor(Math.random() * 90);
        carveWorm(seed, sx, sy, len, 1 + (Math.random() < 0.35 ? 1 : 0));
    }

    // 2) Medium branch tunnels
    const branches = 10 + Math.floor(Math.random() * 8);
    for (let i = 0; i < branches; i++) {
        const sx = 10 + Math.floor(Math.random() * (WORLD_WIDTH - 20));
        const surfaceH = surfaceHeightAt(sx, seed);
        const sy = surfaceH + 10 + Math.floor(Math.random() * Math.min(50, WORLD_HEIGHT - surfaceH - 15));
        carveWorm(seed, sx, sy, 30 + Math.floor(Math.random() * 45), 1);
    }

    // 3) Sparse large chambers (connected feeling, not everywhere)
    const chambers = 8 + Math.floor(Math.random() * 6);
    for (let i = 0; i < chambers; i++) {
        const cx = 12 + Math.floor(Math.random() * (WORLD_WIDTH - 24));
        const surfaceH = surfaceHeightAt(cx, seed);
        const cy = surfaceH + 14 + Math.floor(Math.random() * Math.min(45, WORLD_HEIGHT - surfaceH - 22));
        const rx = 3 + Math.floor(Math.random() * 4);
        const ry = 2 + Math.floor(Math.random() * 3);
        carveEllipsoid(cx, cy, rx, ry, BLOCKS.AIR);
        // Short connector worm out of chamber
        carveWorm(seed, cx, cy, 20 + Math.floor(Math.random() * 25), 1);
    }

    // 4) A few surface cave entrances
    const entrances = 3 + Math.floor(Math.random() * 3);
    for (let i = 0; i < entrances; i++) {
        const sx = 20 + Math.floor(Math.random() * (WORLD_WIDTH - 40));
        const surfaceH = surfaceHeightAt(sx, seed);
        // Vertical-ish shaft down
        let y = surfaceH;
        const depth = 8 + Math.floor(Math.random() * 12);
        for (let d = 0; d < depth; d++) {
            carveDisc(sx, y + d, d < 2 ? 1 : 2, BLOCKS.AIR);
        }
        carveWorm(seed, sx, surfaceH + depth, 35 + Math.floor(Math.random() * 30), 1);
    }

    // 5) Very light cellular open (ONLY near already-open cells – widen tunnels slightly)
    // This smooths walls without creating cheese
    const toOpen = [];
    for (let y = 4; y < WORLD_HEIGHT - 3; y++) {
        for (let x = 2; x < WORLD_WIDTH - 2; x++) {
            const idx = y * WORLD_WIDTH + x;
            if (world[idx] === BLOCKS.AIR) continue;
            let airN = 0;
            for (let oy = -1; oy <= 1; oy++) {
                for (let ox = -1; ox <= 1; ox++) {
                    if (ox === 0 && oy === 0) continue;
                    if (world[(y + oy) * WORLD_WIDTH + (x + ox)] === BLOCKS.AIR) airN++;
                }
            }
            // Only nibble walls that already face open space
            if (airN >= 4 && pseudoNoise(x * 0.3, y * 0.3, seed + 200) < 0.35) {
                toOpen.push(idx);
            }
        }
    }
    for (const idx of toOpen) world[idx] = BLOCKS.AIR;

    // 6) Fill isolated single-block holes (anti-cheese)
    for (let y = 3; y < WORLD_HEIGHT - 3; y++) {
        for (let x = 2; x < WORLD_WIDTH - 2; x++) {
            const idx = y * WORLD_WIDTH + x;
            if (world[idx] !== BLOCKS.AIR) continue;
            let solid = 0;
            for (let oy = -1; oy <= 1; oy++) {
                for (let ox = -1; ox <= 1; ox++) {
                    if (ox === 0 && oy === 0) continue;
                    const n = world[(y + oy) * WORLD_WIDTH + (x + ox)];
                    if (n !== BLOCKS.AIR && n !== BLOCKS.WATER) solid++;
                }
            }
            if (solid >= 7) {
                const surfaceH = surfaceHeightAt(x, seed);
                world[idx] = (y > surfaceH + 50) ? BLOCKS.DEEP_STONE : BLOCKS.STONE;
            }
        }
    }
}

// ---------- POIs ----------
function placeCaveRuin(cx, cy) {
    const w = 5 + Math.floor(Math.random() * 4);
    const h = 3 + Math.floor(Math.random() * 3);
    for (let dy = 0; dy < h; dy++) {
        for (let dx = 0; dx < w; dx++) {
            const x = cx + dx, y = cy - dy;
            if (x < 1 || x >= WORLD_WIDTH - 1 || y < 1 || y >= WORLD_HEIGHT - 1) continue;
            const idx = y * WORLD_WIDTH + x;
            if (dy === 0 || dx === 0 || dx === w - 1) {
                world[idx] = BLOCKS.CAVE_BRICK;
            } else {
                world[idx] = BLOCKS.AIR;
            }
        }
    }
    const chestX = cx + Math.floor(w / 2);
    const chestY = cy - 1;
    if (chestX > 0 && chestX < WORLD_WIDTH && chestY > 0 && chestY < WORLD_HEIGHT) {
        world[chestY * WORLD_WIDTH + chestX] = BLOCKS.CHEST;
        const key = chestKey(chestX, chestY);
        const slots = emptyChestSlots();
        const loot = [
            { type: BLOCKS.COAL, count: 3 + Math.floor(Math.random() * 6) },
            { type: BLOCKS.TORCH, count: 4 },
            { type: BLOCKS.COPPER_INGOT, count: 1 + Math.floor(Math.random() * 3) },
            { type: BLOCKS.IRON_INGOT, count: Math.floor(Math.random() * 3) },
            { type: BLOCKS.GEAR, count: 1 + Math.floor(Math.random() * 2) }
        ];
        if (Math.random() < 0.25) loot.push({ type: BLOCKS.CRYSTAL_SHARD, count: 1 });
        if (Math.random() < 0.12) loot.push({ type: BLOCKS.GOLD_INGOT, count: 1 });
        loot.forEach((item, i) => {
            if (item.count > 0 && i < slots.length) {
                slots[i] = { type: item.type, count: item.count, durability: 0 };
            }
        });
        chestData[key] = slots;
    }
    const torchX = cx + 1;
    const torchY = cy - h + 1;
    if (torchX > 0 && torchX < WORLD_WIDTH && torchY > 0) {
        world[torchY * WORLD_WIDTH + torchX] = BLOCKS.TORCH;
    }
}

function placeGeode(cx, cy) {
    const radius = 3 + Math.floor(Math.random() * 2);
    for (let dy = -radius; dy <= radius; dy++) {
        for (let dx = -radius; dx <= radius; dx++) {
            const dist = Math.sqrt(dx * dx + dy * dy);
            const x = cx + dx, y = cy + dy;
            if (x < 1 || x >= WORLD_WIDTH - 1 || y < 1 || y >= WORLD_HEIGHT - 1) continue;
            const idx = y * WORLD_WIDTH + x;
            if (dist < radius - 1.1) world[idx] = BLOCKS.AIR;
            else if (dist <= radius) world[idx] = Math.random() < 0.5 ? BLOCKS.CRYSTAL : BLOCKS.DEEP_STONE;
        }
    }
}

function placeUndergroundLake(cx, cy) {
    const rx = 4 + Math.floor(Math.random() * 4);
    const ry = 2 + Math.floor(Math.random() * 2);
    for (let dy = -ry; dy <= 1; dy++) {
        for (let dx = -rx; dx <= rx; dx++) {
            if ((dx * dx) / (rx * rx) + (dy * dy) / ((ry + 1) * (ry + 1)) > 1) continue;
            const x = cx + dx, y = cy + dy;
            if (x < 1 || x >= WORLD_WIDTH - 1 || y < 1 || y >= WORLD_HEIGHT - 1) continue;
            world[y * WORLD_WIDTH + x] = dy >= 0 ? BLOCKS.WATER : BLOCKS.AIR;
        }
    }
}

function placeMushroomGrotto(cx, cy) {
    carveEllipsoid(cx, cy, 4, 3, BLOCKS.AIR);
    for (let i = 0; i < 10; i++) {
        const dx = Math.floor((Math.random() - 0.5) * 8);
        const dy = Math.floor((Math.random() - 0.5) * 5);
        const x = cx + dx, y = cy + dy;
        if (x < 1 || x >= WORLD_WIDTH - 1 || y < 1 || y >= WORLD_HEIGHT - 2) continue;
        const below = world[(y + 1) * WORLD_WIDTH + x];
        if (world[y * WORLD_WIDTH + x] === BLOCKS.AIR && below !== BLOCKS.AIR && below !== BLOCKS.WATER) {
            world[y * WORLD_WIDTH + x] = BLOCKS.MUSHROOM;
        }
    }
}

function placeCavePOIs(seed) {
    const ruinCount = 5 + Math.floor(Math.random() * 4);
    for (let i = 0; i < ruinCount; i++) {
        const cx = 12 + Math.floor(Math.random() * (WORLD_WIDTH - 24));
        const surfaceH = surfaceHeightAt(cx, seed);
        const cy = surfaceH + 16 + Math.floor(Math.random() * Math.min(35, WORLD_HEIGHT - surfaceH - 28));
        // Prefer placing in open cave space
        if (world[cy * WORLD_WIDTH + cx] !== BLOCKS.AIR) carveEllipsoid(cx, cy, 3, 2, BLOCKS.AIR);
        placeCaveRuin(cx, cy);
    }
    const geodeCount = 3 + Math.floor(Math.random() * 3);
    for (let i = 0; i < geodeCount; i++) {
        const cx = 10 + Math.floor(Math.random() * (WORLD_WIDTH - 20));
        const surfaceH = surfaceHeightAt(cx, seed);
        const cy = Math.min(WORLD_HEIGHT - 12, surfaceH + 45 + Math.floor(Math.random() * 20));
        placeGeode(cx, cy);
    }
    const lakeCount = 2 + Math.floor(Math.random() * 3);
    for (let i = 0; i < lakeCount; i++) {
        const cx = 15 + Math.floor(Math.random() * (WORLD_WIDTH - 30));
        const surfaceH = surfaceHeightAt(cx, seed);
        const cy = surfaceH + 18 + Math.floor(Math.random() * 25);
        placeUndergroundLake(cx, cy);
    }
    const grottoCount = 3 + Math.floor(Math.random() * 3);
    for (let i = 0; i < grottoCount; i++) {
        const cx = 10 + Math.floor(Math.random() * (WORLD_WIDTH - 20));
        const surfaceH = surfaceHeightAt(cx, seed);
        const cy = surfaceH + 12 + Math.floor(Math.random() * 28);
        placeMushroomGrotto(cx, cy);
    }
}

// ---------- Main generator ----------
function generateContinuousCaves(seedVal) {
    const seed = seedVal || Math.random() * 10000;
    worldSeed = seed;
    world.fill(BLOCKS.AIR);
    wallWorld.fill(BLOCKS.AIR);
    blockDamageMap.fill(0);
    chestData = {};
    openChestKey = null;

    // Precompute surface heights for consistency
    const surfaces = new Int16Array(WORLD_WIDTH);
    const biomes = new Uint8Array(WORLD_WIDTH);
    for (let x = 0; x < WORLD_WIDTH; x++) {
        biomes[x] = getBiomeAt(x, seed);
        surfaces[x] = surfaceHeightAt(x, seed);
    }

    // --- Terrain column fill with real soil layers ---
    for (let x = 0; x < WORLD_WIDTH; x++) {
        const biome = biomes[x];
        const surfaceH = surfaces[x];
        const moisture = fbm(x * 0.0055, 1.5, seed + 29, 4, 2.0, 0.55);

        // Soil depth varies by biome
        let topDepth = 1;
        let soilDepth = 4;
        if (biome === BIOME.DESERT) { topDepth = 1; soilDepth = 5 + Math.floor(Math.random() * 3); }
        else if (biome === BIOME.SNOW || biome === BIOME.TAIGA) { topDepth = 2 + Math.floor(Math.random() * 2); soilDepth = 4; }
        else if (biome === BIOME.MOUNTAIN) { topDepth = 1; soilDepth = 2; }
        else if (biome === BIOME.FOREST) { topDepth = 1; soilDepth = 5; }
        else if (biome === BIOME.PLAINS) { topDepth = 1; soilDepth = 4; }

        // Surface lakes / ponds in wet low areas
        const isPond = (biome === BIOME.PLAINS || biome === BIOME.FOREST) &&
            moisture > 0.72 && surfaces[x] < 36 &&
            pseudoNoise(x * 0.08, 0, seed + 900) > 0.82;

        for (let y = 0; y < WORLD_HEIGHT; y++) {
            const index = y * WORLD_WIDTH + x;

            if (y < surfaceH) {
                // Sky / air (trees placed later)
                continue;
            }

            if (y === surfaceH) {
                if (isPond) {
                    world[index] = BLOCKS.WATER;
                    wallWorld[index] = BLOCKS.DIRT;
                    continue;
                }
                switch (biome) {
                    case BIOME.DESERT:
                        world[index] = BLOCKS.SAND;
                        wallWorld[index] = BLOCKS.SAND;
                        break;
                    case BIOME.SNOW:
                        world[index] = BLOCKS.SNOW_GRASS;
                        wallWorld[index] = BLOCKS.DIRT;
                        break;
                    case BIOME.TAIGA:
                        world[index] = Math.random() < 0.55 ? BLOCKS.SNOW_GRASS : BLOCKS.GRASS;
                        wallWorld[index] = BLOCKS.DIRT;
                        break;
                    case BIOME.MOUNTAIN:
                        world[index] = (Math.random() < 0.65) ? BLOCKS.STONE : BLOCKS.GRASS;
                        wallWorld[index] = BLOCKS.STONE;
                        break;
                    case BIOME.HIGHLAND:
                        world[index] = (Math.random() < 0.4) ? BLOCKS.STONE : BLOCKS.GRASS;
                        wallWorld[index] = BLOCKS.DIRT;
                        break;
                    default:
                        world[index] = BLOCKS.GRASS;
                        wallWorld[index] = BLOCKS.DIRT;
                }
                continue;
            }

            // Below surface
            const depth = y - surfaceH;

            // Snow layer thickness in cold biomes
            if ((biome === BIOME.SNOW || biome === BIOME.TAIGA) && depth <= topDepth) {
                world[index] = BLOCKS.SNOW;
                wallWorld[index] = BLOCKS.DIRT;
                continue;
            }

            // Desert sand layer
            if (biome === BIOME.DESERT && depth <= soilDepth) {
                world[index] = BLOCKS.SAND;
                wallWorld[index] = BLOCKS.SAND;
                continue;
            }

            // Dirt / subsoil
            if (depth <= soilDepth && biome !== BIOME.MOUNTAIN) {
                world[index] = BLOCKS.DIRT;
                wallWorld[index] = BLOCKS.DIRT;
                continue;
            }

            // Mountain thin dirt pockets
            if (biome === BIOME.MOUNTAIN && depth <= 2 && Math.random() < 0.3) {
                world[index] = BLOCKS.DIRT;
                wallWorld[index] = BLOCKS.STONE;
                continue;
            }

            // Stone / deepstone
            wallWorld[index] = BLOCKS.DIRT;
            if (y > surfaceH + 52 || y > WORLD_HEIGHT - 18) {
                world[index] = BLOCKS.DEEP_STONE;
            } else {
                world[index] = BLOCKS.STONE;
            }
        }
    }

    // --- Surface water bodies (wider rivers / lakes) ---
    for (let x = 1; x < WORLD_WIDTH - 1; x++) {
        const biome = biomes[x];
        if (biome === BIOME.DESERT || biome === BIOME.MOUNTAIN) continue;
        const lakeNoise = fbm(x * 0.03, 3.0, seed + 120, 3, 2.0, 0.5);
        if (lakeNoise > 0.78 && surfaces[x] < 40) {
            const surfaceH = surfaces[x];
            // Fill a small basin
            for (let dx = -2; dx <= 2; dx++) {
                const lx = x + dx;
                if (lx < 0 || lx >= WORLD_WIDTH) continue;
                const sh = surfaces[lx];
                for (let dy = 0; dy < 2; dy++) {
                    const y = sh + dy;
                    if (y < WORLD_HEIGHT) {
                        const idx = y * WORLD_WIDTH + lx;
                        if (world[idx] !== BLOCKS.WOOD && world[idx] !== BLOCKS.LEAVES) {
                            world[idx] = BLOCKS.WATER;
                        }
                    }
                }
            }
        }
    }

    // Ice on snow biome water surface
    for (let x = 0; x < WORLD_WIDTH; x++) {
        if (biomes[x] !== BIOME.SNOW && biomes[x] !== BIOME.TAIGA) continue;
        for (let y = 1; y < WORLD_HEIGHT - 1; y++) {
            const idx = y * WORLD_WIDTH + x;
            if (world[idx] === BLOCKS.WATER && world[(y - 1) * WORLD_WIDTH + x] === BLOCKS.AIR) {
                if (Math.random() < 0.7) world[idx] = BLOCKS.ICE;
            }
        }
    }

    // --- Vegetation pass ---
    for (let x = 4; x < WORLD_WIDTH - 4; x++) {
        const biome = biomes[x];
        const surfaceH = surfaces[x];
        const ground = world[surfaceH * WORLD_WIDTH + x];
        if (ground === BLOCKS.WATER || ground === BLOCKS.ICE) continue;

        // Don't plant on steep cliffs (neighbor height delta)
        const dh = Math.abs(surfaces[x] - surfaces[x - 1]) + Math.abs(surfaces[x] - surfaces[x + 1]);
        if (dh > 4) continue;

        const n = pseudoNoise(x, 1, seed + 4);

        if (biome === BIOME.FOREST && ground === BLOCKS.GRASS && n < 0.28) {
            generateTree(x, surfaceH - 1, biome);
        } else if (biome === BIOME.TAIGA && (ground === BLOCKS.GRASS || ground === BLOCKS.SNOW_GRASS) && n < 0.22) {
            generateTree(x, surfaceH - 1, biome);
        } else if (biome === BIOME.SNOW && ground === BLOCKS.SNOW_GRASS && n < 0.10) {
            generateTree(x, surfaceH - 1, biome);
        } else if (biome === BIOME.PLAINS && ground === BLOCKS.GRASS && n < 0.07) {
            generateTree(x, surfaceH - 1, biome);
        } else if (biome === BIOME.DESERT && ground === BLOCKS.SAND && n < 0.04) {
            generateCactus(x, surfaceH - 1);
        } else if (biome === BIOME.HIGHLAND && ground === BLOCKS.GRASS && n < 0.06) {
            generateTree(x, surfaceH - 1, BIOME.PLAINS);
        }
    }

    // --- Caves (connected network, not cheese) ---
    generateCaveNetwork(seed);

    // Moss on stone near air (caves)
    for (let x = 2; x < WORLD_WIDTH - 2; x++) {
        for (let y = 5; y < WORLD_HEIGHT - 4; y++) {
            const idx = y * WORLD_WIDTH + x;
            if (world[idx] !== BLOCKS.STONE) continue;
            let nearAir = false;
            for (let oy = -1; oy <= 1 && !nearAir; oy++) {
                for (let ox = -1; ox <= 1 && !nearAir; ox++) {
                    if (world[(y + oy) * WORLD_WIDTH + (x + ox)] === BLOCKS.AIR) nearAir = true;
                }
            }
            if (nearAir && pseudoNoise(x * 0.25, y * 0.25, seed + 999) < 0.14) {
                world[idx] = BLOCKS.MOSSY_STONE;
            }
        }
    }

    scatterOreVeins(seed);
    placeCavePOIs(seed);

    // Bedrock floor
    for (let x = 0; x < WORLD_WIDTH; x++) {
        world[(WORLD_HEIGHT - 1) * WORLD_WIDTH + x] = BLOCKS.DEEP_STONE;
        world[(WORLD_HEIGHT - 2) * WORLD_WIDTH + x] = BLOCKS.DEEP_STONE;
    }

    spawnPlayer();
    if (typeof player !== 'undefined') {
        player.health = player.maxHealth;
        player.fallSpeed = 0;
    }
}

function spawnPlayer() {
    const spawnX = Math.floor(WORLD_WIDTH / 2);
    for (let y = 0; y < WORLD_HEIGHT; y++) {
        const props = BLOCK_PROPS[world[y * WORLD_WIDTH + spawnX]];
        if (props && props.solid) {
            player.x = spawnX * TILE_SIZE;
            player.y = (y - 3) * TILE_SIZE;
            player.vx = 0;
            player.vy = 0;
            break;
        }
    }
}
