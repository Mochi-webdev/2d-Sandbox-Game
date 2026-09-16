/* rendering.js – textures, draw, lighting, minimap */
let textureCache = {};
let iconCache = {};
let lightCanvas = null, lightCtx = null;
function generateBlockTexture(type) {
    if (textureCache[type]) return textureCache[type];
    const cvs = document.createElement('canvas');
    cvs.width = TILE_SIZE; cvs.height = TILE_SIZE;
    const c = cvs.getContext('2d');
    if (type === BLOCKS.AIR) { textureCache[type] = cvs; return cvs; }
    if (type === BLOCKS.TORCH) {
        c.fillStyle = '#5D4037'; c.fillRect(14, 16, 4, 16);
        c.fillStyle = '#e67e22'; c.beginPath(); c.moveTo(16, 2); c.lineTo(9, 16); c.lineTo(23, 16); c.closePath(); c.fill();
        c.fillStyle = '#f1c40f'; c.beginPath(); c.arc(16, 9, 4, 0, Math.PI * 2); c.fill();
        textureCache[type] = cvs; return cvs;
    }
    if (type === BLOCKS.WATER) {
        const grad = c.createLinearGradient(0, 0, 0, TILE_SIZE);
        grad.addColorStop(0, 'rgba(93,173,226,0.75)'); grad.addColorStop(1, 'rgba(41,128,185,0.88)');
        c.fillStyle = grad; c.fillRect(0, 0, TILE_SIZE, TILE_SIZE);
        c.strokeStyle = 'rgba(255,255,255,0.4)'; c.lineWidth = 1;
        c.beginPath(); c.moveTo(0, 5); c.lineTo(32, 5); c.stroke();
        textureCache[type] = cvs; return cvs;
    }
    c.fillStyle = BLOCK_PROPS[type].color;
    c.fillRect(0, 0, TILE_SIZE, TILE_SIZE);
    const pSize = 4;
    for (let x = 0; x < TILE_SIZE; x += pSize) {
        for (let y = 0; y < TILE_SIZE; y += pSize) {
            const rand = Math.random();
            if (rand < 0.2) { c.fillStyle = 'rgba(0,0,0,0.15)'; c.fillRect(x, y, pSize, pSize); }
            else if (rand > 0.8) { c.fillStyle = 'rgba(255,255,255,0.15)'; c.fillRect(x, y, pSize, pSize); }
        }
    }
    if (type === BLOCKS.GRASS) {
        c.fillStyle = '#4CAF50'; c.fillRect(0, 0, TILE_SIZE, 8);
        c.fillStyle = '#388E3C';
        for (let x = 0; x < TILE_SIZE; x += 4) { if (Math.random() > 0.4) c.fillRect(x, 8, 4, 4); }
    } else if (type === BLOCKS.WOOD) {
        c.fillStyle = '#5D4037'; c.fillRect(0, 0, 4, TILE_SIZE); c.fillRect(TILE_SIZE - 4, 0, 4, TILE_SIZE);
        c.fillStyle = '#4E342E'; c.fillRect(8, 0, 4, TILE_SIZE); c.fillRect(20, 0, 4, TILE_SIZE);
    } else if (type === BLOCKS.PLANK) {
        c.fillStyle = '#A1887F';
        c.fillRect(0, 10, TILE_SIZE, 2); c.fillRect(0, 22, TILE_SIZE, 2);
        c.fillRect(14, 0, 2, 10); c.fillRect(22, 12, 2, 10); c.fillRect(8, 24, 2, 8);
    } else if (type === BLOCKS.LEAVES) {
        c.fillStyle = 'rgba(20, 90, 30, 0.4)';
        for (let i = 0; i < 8; i++) c.fillRect(Math.random() * 24, Math.random() * 24, 8, 8);
    } else if (type === BLOCKS.SAND) {
        c.fillStyle = 'rgba(255,255,255,0.2)';
        for (let i = 0; i < 10; i++) c.fillRect(Math.random() * 28, Math.random() * 28, 2, 2);
    } else if (type === BLOCKS.COAL || type === BLOCKS.IRON || type === BLOCKS.GOLD || type === BLOCKS.COPPER || type === BLOCKS.CRYSTAL) {
        let oreColor = '#111';
        if (type === BLOCKS.IRON) oreColor = '#e0e0e0';
        if (type === BLOCKS.GOLD) oreColor = '#fff000';
        if (type === BLOCKS.COPPER) oreColor = '#e67e22';
        if (type === BLOCKS.CRYSTAL) oreColor = '#d2b4de';
        c.fillStyle = oreColor;
        c.fillRect(6, 6, 8, 8); c.fillRect(18, 14, 6, 8); c.fillRect(10, 20, 8, 6);
        if (type === BLOCKS.CRYSTAL) {
            c.fillStyle = 'rgba(255,255,255,0.5)';
            c.fillRect(8, 8, 3, 3);
        }
    } else if (type === BLOCKS.CRAFTING_BENCH) {
        c.fillStyle = '#6d4c31'; c.fillRect(0, 12, TILE_SIZE, 3);
        c.fillStyle = '#3a3a3a'; c.beginPath(); c.arc(22, 22, 5, 0, Math.PI * 2); c.fill();
        c.strokeStyle = '#1a1a1a'; c.lineWidth = 1; c.stroke();
        c.fillStyle = '#8a5a3c'; c.fillRect(4, 4, 10, 6);
    } else if (type === BLOCKS.FURNACE) {
        c.fillStyle = '#3a3a3a'; c.fillRect(6, 18, 20, 12);
        c.fillStyle = '#e67e22'; c.fillRect(9, 20, 14, 7);
        c.fillStyle = '#ffcf6b'; c.fillRect(12, 22, 8, 3);
    } else if (type === BLOCKS.CHEST) {
        c.fillStyle = '#a66b2b'; c.fillRect(2, 6, 28, 24);
        c.fillStyle = '#8a5520'; c.fillRect(2, 6, 28, 6);
        c.fillStyle = '#5c3a14'; c.fillRect(14, 16, 4, 6);
        c.fillStyle = '#d4a017'; c.fillRect(13, 17, 6, 3);
        c.strokeStyle = '#3e2810'; c.lineWidth = 2; c.strokeRect(2, 6, 28, 24);
    } else if (type === BLOCKS.SNOW || type === BLOCKS.SNOW_GRASS) {
        c.fillStyle = '#eceff1'; c.fillRect(0, 0, TILE_SIZE, TILE_SIZE);
        if (type === BLOCKS.SNOW_GRASS) {
            c.fillStyle = '#90a4ae'; c.fillRect(0, 0, TILE_SIZE, 6);
        }
        c.fillStyle = 'rgba(255,255,255,0.5)';
        for (let i = 0; i < 8; i++) c.fillRect(Math.random()*28, Math.random()*28, 2, 2);
    } else if (type === BLOCKS.ICE) {
        c.fillStyle = '#81d4fa'; c.fillRect(0, 0, TILE_SIZE, TILE_SIZE);
        c.fillStyle = 'rgba(255,255,255,0.35)'; c.fillRect(4, 4, 10, 10); c.fillRect(18, 16, 8, 8);
    } else if (type === BLOCKS.MOSSY_STONE) {
        c.fillStyle = '#556b2f'; c.fillRect(0, 0, TILE_SIZE, TILE_SIZE);
        c.fillStyle = '#607D8B'; c.fillRect(4, 4, 10, 8); c.fillRect(16, 14, 12, 10);
    } else if (type === BLOCKS.MUSHROOM) {
        c.fillStyle = '#f5f5f5'; c.fillRect(14, 16, 4, 14);
        c.fillStyle = '#e74c3c'; c.beginPath(); c.arc(16, 14, 10, Math.PI, 0); c.fill(); c.fillRect(6, 12, 20, 6);
    } else if (type === BLOCKS.CAVE_BRICK) {
        c.fillStyle = '#6d4c41'; c.fillRect(0, 0, TILE_SIZE, TILE_SIZE);
        c.strokeStyle = '#4e342e'; c.lineWidth = 2;
        c.strokeRect(0, 0, 16, 16); c.strokeRect(16, 0, 16, 16);
        c.strokeRect(0, 16, 16, 16); c.strokeRect(16, 16, 16, 16);
    }
    c.strokeStyle = 'rgba(0,0,0,0.15)';
    c.strokeRect(0, 0, TILE_SIZE, TILE_SIZE);
    textureCache[type] = cvs;
    return cvs;
}

function generateItemIcon(type) {
    if (iconCache[type]) return iconCache[type];
    const cvs = document.createElement('canvas');
    cvs.width = 32; cvs.height = 32;
    const c = cvs.getContext('2d');
    const prop = BLOCK_PROPS[type];
    if (!prop || type === BLOCKS.AIR) return cvs;
    if (prop.itemType === ITEM_TYPES.BLOCK) {
        const tex = generateBlockTexture(type);
        c.drawImage(tex, 4, 4, 24, 24);
        c.strokeStyle = '#000'; c.lineWidth = 2; c.strokeRect(4, 4, 24, 24);
    } else if (prop.itemType === ITEM_TYPES.MATERIAL) {
        c.fillStyle = prop.color;
        c.beginPath();
        c.moveTo(5, 20); c.lineTo(11, 10); c.lineTo(21, 10); c.lineTo(27, 20); c.lineTo(23, 26); c.lineTo(9, 26);
        c.closePath(); c.fill();
        c.strokeStyle = '#000'; c.lineWidth = 1.5; c.stroke();
        c.fillStyle = 'rgba(255,255,255,0.35)'; c.fillRect(9, 14, 14, 3);
    } else if (prop.itemType === ITEM_TYPES.TOOL) {
        let headColor = '#8D6E63';
        if (type === BLOCKS.STONE_PICKAXE) headColor = '#90A4AE';
        if (type === BLOCKS.IRON_PICKAXE) headColor = '#cfd8dc';
        if (type === BLOCKS.GOLD_PICKAXE) headColor = '#ffd700';
        if (type === BLOCKS.COPPER_PICKAXE) headColor = '#da8a67';
        if (type === BLOCKS.IRON_SWORD) headColor = '#d7d7d7';
        c.fillStyle = '#5D4037';
        for (let i = 0; i < 14; i++) c.fillRect(8 + i, 22 - i, 3, 3);
        c.fillStyle = headColor;
        c.fillRect(16, 6, 10, 4); c.fillRect(22, 10, 4, 8); c.fillRect(10, 8, 8, 4); c.fillRect(8, 12, 4, 6);
        c.fillStyle = '#fff'; c.fillRect(20, 6, 2, 2);
    } else if (prop.itemType === ITEM_TYPES.ARMOR) {
        c.fillStyle = prop.color;
        if (prop.slot === 'head') { c.beginPath(); c.arc(16, 14, 10, Math.PI, 0); c.fill(); c.fillRect(6, 14, 20, 6); }
        else if (prop.slot === 'chest') { c.fillRect(8, 6, 16, 20); c.fillRect(4, 10, 6, 12); c.fillRect(22, 10, 6, 12); }
        else if (prop.slot === 'legs') { c.fillRect(8, 6, 7, 22); c.fillRect(17, 6, 7, 22); }
        else if (prop.slot === 'feet') { c.fillRect(6, 20, 9, 8); c.fillRect(17, 20, 9, 8); }
        c.strokeStyle = '#000'; c.lineWidth = 1; c.strokeRect(2, 2, 28, 28);
    }
    iconCache[type] = cvs;
    return cvs;
}

function drawCelestial(dayLight) {
    if (dayLight < 0.4) {
        const starAlpha = (0.4 - dayLight) / 0.4;
        ctx.fillStyle = `rgba(255,255,255,${starAlpha})`;
        for (let s = 0; s < STAR_FIELD.length; s++) { const st = STAR_FIELD[s]; ctx.fillRect(st.x * canvas.width, st.y * canvas.height, 2, 2); }
    }
    const angle = dayCycle % (Math.PI * 2);
    const cx = canvas.width * 0.5 + Math.cos(angle) * canvas.width * 0.42;
    const cy = canvas.height * 0.35 - Math.sin(angle) * canvas.height * 0.3;
    ctx.fillStyle = dayLight > 0.5 ? '#fff6a0' : '#dfe6f0';
    ctx.beginPath(); ctx.arc(cx, cy, dayLight > 0.5 ? 22 : 16, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = 'rgba(255,255,255,0.3)'; ctx.lineWidth = 4; ctx.stroke();
}

function drawParallaxMountains(dayLight) {
    const horizonY = canvas.height * 0.6;
    function layer(offsetFactor, amplitude, freq, alpha, baseY, shadeBoost) {
        ctx.beginPath();
        ctx.moveTo(0, canvas.height);
        for (let x = 0; x <= canvas.width; x += 24) {
            const worldX = camera.x * offsetFactor + x;
            const h = Math.sin(worldX * freq) * amplitude + Math.sin(worldX * freq * 2.3 + 50) * amplitude * 0.4;
            ctx.lineTo(x, baseY - h);
        }
        ctx.lineTo(canvas.width, canvas.height);
        ctx.closePath();
        const shade = Math.floor(18 + dayLight * 45) + shadeBoost;
        ctx.fillStyle = `rgba(${shade},${shade + 8},${shade + 26},${alpha})`;
        ctx.fill();
    }
    layer(0.15, 55, 0.004, 0.5, horizonY - 30, 0);
    layer(0.32, 85, 0.006, 0.75, horizonY + 5, -8);
}

function ensureLightCanvas() {
    if (!lightCanvas || lightCanvas.width !== canvas.width || lightCanvas.height !== canvas.height) {
        lightCanvas = document.createElement('canvas');
        lightCanvas.width = canvas.width; lightCanvas.height = canvas.height;
        lightCtx = lightCanvas.getContext('2d');
    }
}

function renderLighting(dayLight, startCol, endCol, startRow, endRow) {
    if (!settings.lighting) return;
    const darkness = (1 - dayLight) * 0.82;
    if (darkness <= 0.02) return;

    ensureLightCanvas();
    lightCtx.globalCompositeOperation = 'source-over';
    lightCtx.clearRect(0, 0, lightCanvas.width, lightCanvas.height);
    lightCtx.fillStyle = `rgba(6,8,20,${darkness})`;
    lightCtx.fillRect(0, 0, lightCanvas.width, lightCanvas.height);

    lightCtx.globalCompositeOperation = 'destination-out';
    function cut(wx, wy, radius, intensity) {
        const sx = wx - camera.x, sy = wy - camera.y;
        const grad = lightCtx.createRadialGradient(sx, sy, 0, sx, sy, radius);
        grad.addColorStop(0, `rgba(255,255,255,${intensity})`);
        grad.addColorStop(0.55, `rgba(255,255,255,${intensity * 0.55})`);
        grad.addColorStop(1, 'rgba(255,255,255,0)');
        lightCtx.fillStyle = grad;
        lightCtx.beginPath(); lightCtx.arc(sx, sy, radius, 0, Math.PI * 2); lightCtx.fill();
    }

    cut(player.x + player.width / 2, player.y + player.height / 2, TILE_SIZE * 6, 1.0);
    otherPlayers.forEach(p => cut(p.x + player.width / 2, p.y + player.height / 2, TILE_SIZE * 5.5, 0.95));

    for (let r = startRow; r < endRow; r++) {
        for (let c = startCol; c < endCol; c++) {
            const b = world[r * WORLD_WIDTH + c];
            const props = BLOCK_PROPS[b];
            if (props && props.light) cut(c * TILE_SIZE + 16, r * TILE_SIZE + 16, TILE_SIZE * (props.lightRadius || 4), 1.0);
        }
    }

    lightCtx.globalCompositeOperation = 'source-over';
    ctx.drawImage(lightCanvas, 0, 0);
}

function drawWarmGlow(startCol, endCol, startRow, endRow, dayLight) {
    if (!settings.lighting) return;
    if (dayLight > 0.6) return;
    ctx.save();
    ctx.globalCompositeOperation = 'lighter';
    function glow(wx, wy, radius, r, g, b) {
        const sx = wx - camera.x, sy = wy - camera.y;
        const grad = ctx.createRadialGradient(sx, sy, 0, sx, sy, radius);
        grad.addColorStop(0, `rgba(${r},${g},${b},0.35)`);
        grad.addColorStop(1, `rgba(${r},${g},${b},0)`);
        ctx.fillStyle = grad;
        ctx.beginPath(); ctx.arc(sx, sy, radius, 0, Math.PI * 2); ctx.fill();
    }
    for (let r = startRow; r < endRow; r++) {
        for (let c = startCol; c < endCol; c++) {
            const b = world[r * WORLD_WIDTH + c];
            if (b === BLOCKS.TORCH) glow(c * TILE_SIZE + 16, r * TILE_SIZE + 16, TILE_SIZE * 2.4, 255, 170, 80);
            else if (b === BLOCKS.FURNACE) glow(c * TILE_SIZE + 16, r * TILE_SIZE + 16, TILE_SIZE * 2.1, 255, 110, 60);
            else if (b === BLOCKS.CRYSTAL) glow(c * TILE_SIZE + 16, r * TILE_SIZE + 16, TILE_SIZE * 2.0, 180, 100, 220);
            else if (b === BLOCKS.MUSHROOM) glow(c * TILE_SIZE + 16, r * TILE_SIZE + 16, TILE_SIZE * 1.4, 220, 80, 80);
        }
    }
    ctx.restore();
}

function drawVignette() {
    if (!settings.vignette) return;
    const grad = ctx.createRadialGradient(canvas.width / 2, canvas.height / 2, canvas.height * 0.35, canvas.width / 2, canvas.height / 2, canvas.height * 0.78);
    grad.addColorStop(0, 'rgba(0,0,0,0)');
    grad.addColorStop(1, 'rgba(0,0,0,0.4)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
}

function render(dt) {
    dayCycle += dt * 0.000012;
    const dayLight = (Math.sin(dayCycle) + 1) / 2;

    const shaking = shakeTime > 0;
    if (shaking) {
        ctx.save();
        const ox = (Math.random() - 0.5) * shakeMag;
        const oy = (Math.random() - 0.5) * shakeMag;
        ctx.translate(ox, oy);
        shakeTime--; shakeMag *= 0.9;
    }

    const skyTopR = Math.floor(15 + dayLight * 115);
    const skyTopG = Math.floor(15 + dayLight * 175);
    const skyTopB = Math.floor(30 + dayLight * 225);
    const skyGrad = ctx.createLinearGradient(0, 0, 0, canvas.height);
    skyGrad.addColorStop(0, `rgb(${skyTopR}, ${skyTopG}, ${skyTopB})`);
    skyGrad.addColorStop(1, `rgb(${Math.floor(skyTopR * 0.6)}, ${Math.floor(skyTopG * 0.6)}, ${Math.floor(skyTopB * 0.6)})`);
    ctx.fillStyle = skyGrad;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    drawCelestial(dayLight);
    drawParallaxMountains(dayLight);

    const startCol = Math.max(0, Math.floor(camera.x / TILE_SIZE));
    const endCol = Math.min(WORLD_WIDTH, Math.ceil((camera.x + canvas.width) / TILE_SIZE));
    const startRow = Math.max(0, Math.floor(camera.y / TILE_SIZE));
    const endRow = Math.min(WORLD_HEIGHT, Math.ceil((camera.y + canvas.height) / TILE_SIZE));

    for (let r = startRow; r < endRow; r++) {
        for (let c = startCol; c < endCol; c++) {
            const index = r * WORLD_WIDTH + c;
            const block = world[index];
            const wall = wallWorld[index];
            const x = Math.floor(c * TILE_SIZE - camera.x);
            const y = Math.floor(r * TILE_SIZE - camera.y);

            if (block === BLOCKS.AIR && wall !== BLOCKS.AIR) {
                ctx.fillStyle = '#1c1310';
                ctx.fillRect(x, y, TILE_SIZE, TILE_SIZE);
            } else if (block !== BLOCKS.AIR) {
                const tex = generateBlockTexture(block);
                ctx.drawImage(tex, x, y);
                if (blockDamageMap[index] > 0 && !BLOCK_PROPS[block].unbreakable) {
                    const dmgRatio = blockDamageMap[index] / BLOCK_PROPS[block].hardness;
                    ctx.fillStyle = `rgba(0, 0, 0, ${dmgRatio * 0.75})`;
                    ctx.fillRect(x, y, TILE_SIZE, TILE_SIZE);
                }
            }
        }
    }

    particles.forEach((p, idx) => {
        p.x += p.vx; p.y += p.vy; p.life--;
        ctx.fillStyle = p.color;
        const size = Math.max(1, Math.floor((p.life / p.maxLife) * 5));
        ctx.fillRect(p.x - camera.x, p.y - camera.y, size, size);
        if (p.life <= 0) particles.splice(idx, 1);
    });

    otherPlayers.forEach(p => drawPlayer(p.x, p.y, p.facing, false, p.name, p.animTime || 0, p.skin, p.armor));
    drawPlayer(player.x, player.y, player.facing, true, document.getElementById('player-name').value, player.animTime, currentSkin, {
        head: equipment.head.type, chest: equipment.chest.type, legs: equipment.legs.type, feet: equipment.feet.type
    });

    renderLighting(dayLight, startCol, endCol, startRow, endRow);
    drawWarmGlow(startCol, endCol, startRow, endRow, dayLight);

    if (shaking) ctx.restore();

    drawVignette();
}

function drawPlayer(px, py, facing, isSelf, name, animTime, skin, armor) {
    skin = skin || currentSkin;
    const sx = Math.floor(px - camera.x);
    const sy = Math.floor(py - camera.y);
    const legOffset = Math.sin(animTime * 2) * 4;

    ctx.fillStyle = skin.shirt || '#e74c3c';
    ctx.fillRect(sx + 2, sy + 10, player.width - 4, 16);

    ctx.fillStyle = '#2c3e50';
    ctx.fillRect(sx + 3, sy + 26, 6, 12 + legOffset);
    ctx.fillRect(sx + 11, sy + 26, 6, 12 - legOffset);
    ctx.fillStyle = skin.pants || '#2c3e50';
    ctx.fillRect(sx + 3, sy + 26, 6, 8 + legOffset * 0.5);
    ctx.fillRect(sx + 11, sy + 26, 6, 8 - legOffset * 0.5);

    ctx.fillStyle = skin.skin || '#ffcc99';
    ctx.fillRect(sx + 1, sy, player.width - 2, 10);

    ctx.fillStyle = skin.hair || '#3b2412';
    ctx.fillRect(sx, sy - 2, player.width, 4);

    ctx.fillStyle = '#000';
    const eyeX = facing === 'right' ? sx + 13 : sx + 4;
    ctx.fillRect(eyeX, sy + 3, 3, 3);

    if (armor) {
        if (armor.chest && armor.chest !== BLOCKS.AIR && BLOCK_PROPS[armor.chest]) {
            ctx.fillStyle = BLOCK_PROPS[armor.chest].color;
            ctx.fillRect(sx + 2, sy + 10, player.width - 4, 10);
        }
        if (armor.legs && armor.legs !== BLOCKS.AIR && BLOCK_PROPS[armor.legs]) {
            ctx.fillStyle = BLOCK_PROPS[armor.legs].color;
            ctx.fillRect(sx + 3, sy + 26, 6, 8); ctx.fillRect(sx + 11, sy + 26, 6, 8);
        }
        if (armor.head && armor.head !== BLOCKS.AIR && BLOCK_PROPS[armor.head]) {
            ctx.fillStyle = BLOCK_PROPS[armor.head].color;
            ctx.fillRect(sx, sy - 2, player.width, 6);
        }
        if (armor.feet && armor.feet !== BLOCKS.AIR && BLOCK_PROPS[armor.feet]) {
            ctx.fillStyle = BLOCK_PROPS[armor.feet].color;
            ctx.fillRect(sx + 3, sy + 34, 6, 4); ctx.fillRect(sx + 11, sy + 34, 6, 4);
        }
    }

    if (isSelf) {
        ctx.strokeStyle = 'rgba(255,255,255,0.5)'; ctx.lineWidth = 1;
        ctx.strokeRect(sx + 1, sy - 2, player.width - 2, player.height + 2);
    }

    ctx.fillStyle = 'rgba(0,0,0,0.6)';
    ctx.fillRect(sx + player.width / 2 - 24, sy - 18, 48, 12);
    ctx.strokeStyle = '#3a3a52';
    ctx.strokeRect(sx + player.width / 2 - 24, sy - 18, 48, 12);

    ctx.fillStyle = '#fff';
    ctx.font = '6px "Press Start 2P"';
    ctx.textAlign = 'center';
    ctx.fillText(name.substring(0, 8), sx + player.width / 2, sy - 10);
}

function renderMinimap() {
    minimapCtx.clearRect(0, 0, 150, 75);
    minimapCtx.fillStyle = '#0d0e15';
    minimapCtx.fillRect(0, 0, 150, 75);
    const stepX = WORLD_WIDTH / 150;
    for (let mx = 0; mx < 150; mx++) {
        const wx = Math.floor(mx * stepX);
        for (let y = 0; y < WORLD_HEIGHT; y++) {
            const b = world[y * WORLD_WIDTH + wx];
            if (b !== BLOCKS.AIR) {
                const my = Math.floor((y / WORLD_HEIGHT) * 75);
                minimapCtx.fillStyle = BLOCK_PROPS[b].color === 'transparent' ? '#000' : BLOCK_PROPS[b].color;
                minimapCtx.fillRect(mx, my, 1, 2);
                break;
            }
        }
    }
    const px = Math.floor((player.x / TILE_SIZE / WORLD_WIDTH) * 150);
    const py = Math.floor((player.y / TILE_SIZE / WORLD_HEIGHT) * 75);
    minimapCtx.fillStyle = '#e74c3c';
    minimapCtx.fillRect(px - 1, py - 1, 3, 3);
    otherPlayers.forEach(p => {
        const ox = Math.floor((p.x / TILE_SIZE / WORLD_WIDTH) * 150);
        const oy = Math.floor((p.y / TILE_SIZE / WORLD_HEIGHT) * 75);
        minimapCtx.fillStyle = '#3498db';
        minimapCtx.fillRect(ox - 1, oy - 1, 3, 3);
    });
}
