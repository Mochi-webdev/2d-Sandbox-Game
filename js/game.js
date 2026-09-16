
const canvas = document.getElementById('gameCanvas');
const ctx = canvas.getContext('2d');

let firebaseApp = null, auth = null, db = null, firebaseReady = false;
try {
    if (firebaseConfig.apiKey && firebaseConfig.apiKey !== 'YOUR_API_KEY') {
        firebaseApp = firebase.initializeApp(firebaseConfig);
        auth = firebase.auth();
        db = firebase.firestore();
        firebaseReady = true;
    }
} catch (e) { console.error('Firebase init failed:', e); firebaseReady = false; }

let currentUser = null;
let profile = { playerName: 'Explorer', settings: defaultSettingsObj(), activeSkin: defaultSkinObj() };
let skinsCache = [];
let serversCache = [];
let worldsMetaCache = [];
let activeCloudWorldId = null;

function userDocRef() { return db.collection('users').doc(currentUser.uid); }
function skinsColRef() { return userDocRef().collection('skins'); }
function serversColRef() { return userDocRef().collection('servers'); }
function worldsMetaColRef() { return userDocRef().collection('worldsMeta'); }
function worldsDataColRef() { return userDocRef().collection('worldsData'); }

function requireOnline() {
    if (!firebaseReady) { notify('This needs Firebase configured.'); return false; }
    if (!currentUser) { notify('Still connecting to your account - try again in a moment.'); return false; }
    return true;
}

let settings = defaultSettingsObj();
let currentSkin = defaultSkinObj();

let player = {
    x: 0, y: 0, vx: 0, vy: 0, width: 20, height: 38,
    grounded: false, facing: 'right', animTime: 0, isWalking: false,
    health: 100, maxHealth: 100, fallSpeed: 0
};

let gameMode = 'survival';
let isMultiplayer = false;
let isHost = false;
let otherPlayers = new Map();

let hotbar = [emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot()];
let inventoryStorage = new Array(24).fill(null).map(emptySlot);
let equipment = { head: emptySlot(), chest: emptySlot(), legs: emptySlot(), feet: emptySlot() };
let selectedSlot = 0;

let craftingGrid = new Array(9).fill(null).map(emptySlot);
let craftResult = { type: BLOCKS.AIR, count: 0, durability: 0 };
let heldItem = null;
let currentCraftingStation = null;

let camera = { x: 0, y: 0 };
let particles = [];
let lastFrameTime = performance.now();
let dayCycle = 0;
let frameCounter = 0;
let shakeTime = 0, shakeMag = 0;
const keys = {};
const STAR_FIELD = Array.from({ length: 90 }, () => ({ x: Math.random(), y: Math.random() * 0.65 }));


function resetHotbar() {
    hotbar = [emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot()];
    inventoryStorage = new Array(24).fill(null).map(emptySlot);
    equipment = { head: emptySlot(), chest: emptySlot(), legs: emptySlot(), feet: emptySlot() };
    selectedSlot = 0;
}

function triggerShake(time, mag) {
    if (!settings.shake) return;
    shakeTime = Math.max(shakeTime, time);
    shakeMag = Math.max(shakeMag, mag);
}
function getTotalDefense() {
    let d = 0;
    for (const key in equipment) {
        const it = equipment[key];
        if (it.type !== BLOCKS.AIR && BLOCK_PROPS[it.type]) d += (BLOCK_PROPS[it.type].defense || 0);
    }
    return Math.min(80, d);
}
function notify(text) {
    const el = document.getElementById('notification');
    el.innerText = text;
    el.style.display = 'block';
    clearTimeout(notify._t);
    notify._t = setTimeout(() => { el.style.display = 'none'; }, 2800);
}






function isNearStation(blockType, radius) {
    radius = radius || 6;
    const pc = Math.floor((player.x + player.width / 2) / TILE_SIZE);
    const pr = Math.floor((player.y + player.height / 2) / TILE_SIZE);
    for (let r = pr - radius; r <= pr + radius; r++) {
        for (let c = pc - radius; c <= pc + radius; c++) {
            if (r < 0 || r >= WORLD_HEIGHT || c < 0 || c >= WORLD_WIDTH) continue;
            const block = world[r * WORLD_WIDTH + c];
            const props = BLOCK_PROPS[block];
            if (props && props.name && props.name.includes(blockType === BLOCKS.CRAFTING_BENCH ? "Bench" : "Furnace")) return true;
        }
    }
    return false;
}

function resizeCanvas() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
window.addEventListener('resize', resizeCanvas);
resizeCanvas();

window.addEventListener('keydown', e => {
    const tag = document.activeElement ? document.activeElement.tagName : '';
    const typing = tag === 'INPUT' || tag === 'TEXTAREA';
    if (typing) return;
    if (!e.code) return;

    keys[e.code] = true;
    if (e.code.startsWith('Digit')) {
        const num = parseInt(e.code.replace('Digit', ''), 10) - 1;
        if (num >= 0 && num < hotbar.length) { selectedSlot = num; updateUI(); }
    }
    if (e.code === 'KeyC') openBasicCrafting();
    if (e.code === 'KeyE') toggleInventoryModal();
    if (e.code === 'Escape') { closeCraftingModal(); closeChestModal(); toggleInventoryModal(true); closeWorldsModal(); }
});
window.addEventListener('keyup', e => { if (e.code) keys[e.code] = false; });

window.addEventListener('mousemove', e => {
    const dragEl = document.getElementById('dragged-item');
    if (heldItem && heldItem.count > 0 && heldItem.type !== BLOCKS.AIR) {
        dragEl.style.display = 'block';
        dragEl.style.left = e.clientX + 'px';
        dragEl.style.top = e.clientY + 'px';
        const dragCtx = dragEl.getContext('2d');
        dragCtx.clearRect(0, 0, 32, 32);
        dragCtx.drawImage(generateItemIcon(heldItem.type), 0, 0);
    } else {
        dragEl.style.display = 'none';
    }
});

canvas.addEventListener('mousedown', e => {
    if (document.getElementById('ui-layer').style.display === 'none') return;

    const rect = canvas.getBoundingClientRect();
    const mouseX = e.clientX - rect.left + camera.x;
    const mouseY = e.clientY - rect.top + camera.y;

    const playerCenterX = player.x + player.width / 2;
    const playerCenterY = player.y + player.height / 2;
    const dist = Math.hypot(mouseX - playerCenterX, mouseY - playerCenterY);

    if (gameMode !== 'creative' && dist > INTERACTION_RANGE) { notify("Out of mining range!"); return; }

    const col = Math.floor(mouseX / TILE_SIZE);
    const row = Math.floor(mouseY / TILE_SIZE);

    if (col >= 0 && col < WORLD_WIDTH && row >= 0 && row < WORLD_HEIGHT) {
        const index = row * WORLD_WIDTH + col;

        if (e.button === 0) {
            const block = world[index];
            // Click Crafting Bench or Furnace to open their dedicated crafting UI
            // Hold Shift while clicking to mine them instead
            if (!keys['ShiftLeft'] && !keys['ShiftRight']) {
                if (block === BLOCKS.CRAFTING_BENCH) {
                    openCraftingUI('bench');
                    notify('Opened Crafting Bench (Shift+click to mine)');
                    return;
                }
                if (block === BLOCKS.FURNACE) {
                    openCraftingUI('furnace');
                    notify('Opened Furnace (Shift+click to mine)');
                    return;
                }
                if (block === BLOCKS.CHEST) {
                    openChestModal(col, row);
                    return;
                }
            }
            if (block !== BLOCKS.AIR && !BLOCK_PROPS[block].unbreakable) {
                let activeItem = hotbar[selectedSlot];
                let miningPower = HAND_POWER;
                let usingTool = false;

                if (activeItem && BLOCK_PROPS[activeItem.type] && BLOCK_PROPS[activeItem.type].itemType === ITEM_TYPES.TOOL) {
                    miningPower = BLOCK_PROPS[activeItem.type].power;
                    usingTool = true;
                }

                const requiredPower = BLOCK_PROPS[block].minPower || 0;
                if (gameMode !== 'creative' && miningPower < requiredPower) {
                    notify(BLOCK_PROPS[block].name + " is too tough - need a better pickaxe!");
                    return;
                }

                if (usingTool && gameMode !== 'creative') {
                    activeItem.durability--;
                    if (activeItem.durability <= 0) {
                        hotbar[selectedSlot] = emptySlot();
                        notify("Tool Broke!");
                    }
                }

                blockDamageMap[index] += miningPower;
                addParticle(col * TILE_SIZE + 16, row * TILE_SIZE + 16, BLOCK_PROPS[block].color);

                if (blockDamageMap[index] >= BLOCK_PROPS[block].hardness || gameMode === 'creative') {
                    // If breaking a chest, dump its contents into player inventory first
                    if (block === BLOCKS.CHEST) {
                        const key = chestKey(col, row);
                        const slots = chestData[key] || [];
                        slots.forEach(s => {
                            if (s && s.type !== BLOCKS.AIR && s.count > 0) addItemToInventory(s.type, s.count);
                        });
                        delete chestData[key];
                        if (openChestKey === key) closeChestModal();
                    }
                    if (gameMode !== 'creative') addItemToInventory(block, 1);
                    world[index] = BLOCKS.AIR;
                    blockDamageMap[index] = 0;
                    broadcastBlockChange(col, row, BLOCKS.AIR);
                    triggerShake(4, 3);
                }
                updateUI();
            }
        } else if (e.button === 2) {
            const active = hotbar[selectedSlot];
            if (world[index] === BLOCKS.AIR && active && BLOCK_PROPS[active.type] && BLOCK_PROPS[active.type].itemType === ITEM_TYPES.BLOCK && active.count > 0) {
                world[index] = active.type;
                if (active.type === BLOCKS.CHEST) {
                    chestData[chestKey(col, row)] = emptyChestSlots();
                }
                if (gameMode !== 'creative') {
                    active.count--;
                    if (active.count <= 0) hotbar[selectedSlot] = emptySlot();
                }
                broadcastBlockChange(col, row, active.type);
                updateUI();
            }
        }
    }
});
canvas.addEventListener('contextmenu', e => e.preventDefault());



function addParticle(x, y, color) {
    for (let i = 0; i < 6; i++) {
        particles.push({ x, y, vx: (Math.random() - 0.5) * 5, vy: (Math.random() - 0.5) * 5, life: 16, maxLife: 16, color });
    }
}

function maybeSpawnAmbientDust() {
    if (!settings.dust) return;
    if (Math.random() > 0.06) return;
    const tc = Math.floor(player.x / TILE_SIZE);
    const tr = Math.floor(player.y / TILE_SIZE);
    if (tr < 0 || tr >= WORLD_HEIGHT || tc < 0 || tc >= WORLD_WIDTH) return;
    const underground = wallWorld[tr * WORLD_WIDTH + tc] !== BLOCKS.AIR;
    if (!underground) return;
    particles.push({
        x: player.x + (Math.random() - 0.5) * 500, y: player.y + (Math.random() - 0.5) * 400,
        vx: (Math.random() - 0.5) * 0.25, vy: -Math.random() * 0.25,
        life: 140, maxLife: 140, color: 'rgba(190,190,210,0.22)'
    });
}

function updatePhysics(dt) {
    const timeFactor = Math.min(dt / 16.6, 2.0);
    const accel = 0.85 * timeFactor;
    const maxSpeed = 3.8;
    const friction = 0.75;

    player.isWalking = false;

    if (keys['KeyA'] || keys['ArrowLeft']) { player.vx = Math.max(player.vx - accel, -maxSpeed); player.facing = 'left'; player.isWalking = true; }
    else if (keys['KeyD'] || keys['ArrowRight']) { player.vx = Math.min(player.vx + accel, maxSpeed); player.facing = 'right'; player.isWalking = true; }
    else { player.vx *= Math.pow(friction, timeFactor); }

    if ((keys['KeyW'] || keys['Space'] || keys['ArrowUp']) && player.grounded) { player.vy = -10.0; player.grounded = false; }

    const wasGrounded = player.grounded;
    player.grounded = false;

    player.vy += 0.52 * timeFactor;
    if (player.vy > 12) player.vy = 12;
    player.fallSpeed = Math.max(player.fallSpeed || 0, player.vy);

    player.x += player.vx * timeFactor;
    checkCollision(true);
    player.y += player.vy * timeFactor;
    checkCollision(false);

    if (player.grounded && !wasGrounded) {
        if (gameMode !== 'creative' && player.fallSpeed > 9) {
            let dmg = Math.floor((player.fallSpeed - 9) * 6);
            const defense = getTotalDefense();
            dmg = Math.max(0, Math.floor(dmg * (1 - defense / 100)));
            if (dmg > 0) {
                player.health = Math.max(0, player.health - dmg);
                if (dmg > 3) notify('Ouch! -' + dmg + ' HP');
                updateHealthUI();
                if (player.health <= 0) {
                    notify('You died! Respawning...');
                    spawnPlayer();
                    player.health = player.maxHealth;
                    updateHealthUI();
                }
            }
        }
        player.fallSpeed = 0;
    }

    if (player.isWalking) player.animTime += 0.15 * timeFactor;
    else player.animTime = 0;

    const targetCamX = clamp(player.x + player.width / 2 - canvas.width / 2, 0, Math.max(0, WORLD_WIDTH * TILE_SIZE - canvas.width));
    const targetCamY = clamp(player.y + player.height / 2 - canvas.height / 2, 0, Math.max(0, WORLD_HEIGHT * TILE_SIZE - canvas.height));
    const camLerp = settings.smoothCamera ? Math.min(1, 0.15 * timeFactor) : 1;
    camera.x += (targetCamX - camera.x) * camLerp;
    camera.y += (targetCamY - camera.y) * camLerp;

    maybeSpawnAmbientDust();
    if (isMultiplayer) broadcastPlayerPos();
}

function checkCollision(horizontal) {
    const left = Math.floor(player.x / TILE_SIZE);
    const right = Math.floor((player.x + player.width) / TILE_SIZE);
    const top = Math.floor(player.y / TILE_SIZE);
    const bottom = Math.floor((player.y + player.height) / TILE_SIZE);

    for (let r = top; r <= bottom; r++) {
        for (let c = left; c <= right; c++) {
            if (r >= 0 && r < WORLD_HEIGHT && c >= 0 && c < WORLD_WIDTH) {
                const blockType = world[r * WORLD_WIDTH + c];
                if (BLOCK_PROPS[blockType].solid) {
                    if (horizontal) {
                        if (player.vx > 0) player.x = c * TILE_SIZE - player.width - 0.01;
                        if (player.vx < 0) player.x = (c + 1) * TILE_SIZE + 0.01;
                        player.vx = 0;
                    } else {
                        if (player.vy > 0) { player.y = r * TILE_SIZE - player.height - 0.01; player.grounded = true; }
                        if (player.vy < 0) player.y = (r + 1) * TILE_SIZE + 0.01;
                        player.vy = 0;
                    }
                }
            }
        }
    }
}


















const minimapCanvas = document.getElementById('minimap-canvas');
const minimapCtx = minimapCanvas.getContext('2d');
























































function hashString(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) { hash = (hash << 5) - hash + str.charCodeAt(i); hash |= 0; }
    return Math.abs(hash);
}
















function gameLoop(now) {
    const dt = now - lastFrameTime;
    lastFrameTime = now;
    updatePhysics(dt);
    render(dt);
    frameCounter++;
    if (frameCounter % 15 === 0) renderMinimap();
    if (frameCounter % 60 === 0 && isMultiplayer) updatePlayerCountDisplay();
    if (frameCounter % 300 === 0 && player.health < player.maxHealth) { player.health = Math.min(player.maxHealth, player.health + 2); updateHealthUI(); }
    requestAnimationFrame(gameLoop);
}

const DEMO_W = 110, DEMO_H = 60;
let demoWorld = new Uint8Array(DEMO_W * DEMO_H);
let demoWall = new Uint8Array(DEMO_W * DEMO_H);
let demoCamX = 0;

function generateDemoWorld() {
    const seed = Math.random() * 10000;
    demoWorld.fill(BLOCKS.AIR);
    demoWall.fill(BLOCKS.AIR);
    for (let x = 0; x < DEMO_W; x++) {
        const biomeNoise = pseudoNoise(x * 0.02, 0, seed + 500);
        const isDesert = biomeNoise < 0.25;
        let surfaceHeight = Math.floor(Math.sin(x * 0.09 + seed) * 6) + 22;
        for (let y = 0; y < DEMO_H; y++) {
            const index = y * DEMO_W + x;
            if (y > surfaceHeight) {
                demoWall[index] = BLOCKS.DIRT;
                const c1 = Math.sin(x * 0.1 + y * 0.06 + seed);
                const c2 = Math.cos(x * 0.06 - y * 0.1 + seed * 2);
                const wormVal = Math.abs(c1 * c2);
                if (wormVal > 0.22 || y <= surfaceHeight + 2) {
                    if (y > surfaceHeight + 10) {
                        const ore = pseudoNoise(x * 0.1, y * 0.1, seed);
                        if (ore < 0.05) demoWorld[index] = BLOCKS.GOLD;
                        else if (ore < 0.12) demoWorld[index] = BLOCKS.IRON;
                        else if (ore < 0.2) demoWorld[index] = BLOCKS.COAL;
                        else demoWorld[index] = (y > surfaceHeight + 35) ? BLOCKS.DEEP_STONE : BLOCKS.STONE;
                    } else demoWorld[index] = (isDesert) ? BLOCKS.SAND : BLOCKS.DIRT;
                }
            } else if (y === surfaceHeight) {
                if (isDesert) { demoWorld[index] = BLOCKS.SAND; demoWall[index] = BLOCKS.SAND; }
                else {
                    demoWorld[index] = BLOCKS.GRASS; demoWall[index] = BLOCKS.DIRT;
                    if (x > 3 && x < DEMO_W - 3 && pseudoNoise(x, 1, seed) < 0.15) {
                        const th = 3 + Math.floor(Math.random() * 2);
                        for (let i = 0; i < th; i++) { const ty = surfaceHeight - 1 - i; if (ty >= 0) demoWorld[ty * DEMO_W + x] = BLOCKS.WOOD; }
                        const leafTop = surfaceHeight - 1 - th;
                        for (let lx = -1; lx <= 1; lx++) {
                            for (let ly = -1; ly <= 1; ly++) {
                                const li = (leafTop + ly) * DEMO_W + (x + lx);
                                if (li >= 0 && li < demoWorld.length && demoWorld[li] === BLOCKS.AIR) demoWorld[li] = BLOCKS.LEAVES;
                            }
                        }
                    }
                }
            }
        }
    }
}
generateDemoWorld();

function menuBackgroundLoop(now) {
    const overlay = document.getElementById('menu-overlay');
    if (overlay.style.display === 'none') return;

    const dt = now - (menuBackgroundLoop.last || now);
    menuBackgroundLoop.last = now;

    dayCycle += dt * 0.000012;
    const dayLight = (Math.sin(dayCycle) + 1) / 2;

    const skyTopR = Math.floor(15 + dayLight * 115);
    const skyTopG = Math.floor(15 + dayLight * 175);
    const skyTopB = Math.floor(30 + dayLight * 225);
    const grad = ctx.createLinearGradient(0, 0, 0, canvas.height);
    grad.addColorStop(0, `rgb(${skyTopR}, ${skyTopG}, ${skyTopB})`);
    grad.addColorStop(1, `rgb(${Math.floor(skyTopR * 0.6)}, ${Math.floor(skyTopG * 0.6)}, ${Math.floor(skyTopB * 0.6)})`);
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    drawCelestial(dayLight);
    drawParallaxMountains(dayLight);

    demoCamX += dt * 0.018;
    const worldPxW = DEMO_W * TILE_SIZE;
    if (demoCamX > worldPxW - canvas.width) demoCamX = 0;
    const demoCamY = Math.max(0, (DEMO_H * TILE_SIZE - canvas.height) / 2);

    const startCol = Math.max(0, Math.floor(demoCamX / TILE_SIZE));
    const endCol = Math.min(DEMO_W, Math.ceil((demoCamX + canvas.width) / TILE_SIZE));
    const startRow = Math.max(0, Math.floor(demoCamY / TILE_SIZE));
    const endRow = Math.min(DEMO_H, Math.ceil((demoCamY + canvas.height) / TILE_SIZE));

    for (let r = startRow; r < endRow; r++) {
        for (let c = startCol; c < endCol; c++) {
            const index = r * DEMO_W + c;
            const block = demoWorld[index];
            const wall = demoWall[index];
            const x = Math.floor(c * TILE_SIZE - demoCamX);
            const y = Math.floor(r * TILE_SIZE - demoCamY);
            if (block === BLOCKS.AIR && wall !== BLOCKS.AIR) { ctx.fillStyle = '#1c1310'; ctx.fillRect(x, y, TILE_SIZE, TILE_SIZE); }
            else if (block !== BLOCKS.AIR) ctx.drawImage(generateBlockTexture(block), x, y);
        }
    }

    drawVignette();
    requestAnimationFrame(menuBackgroundLoop);
}
requestAnimationFrame(menuBackgroundLoop);

// --- Auth bootstrap ---
if (firebaseReady) {
    auth.onAuthStateChanged(async user => {
        if (!user) { auth.signInAnonymously().catch(e => notify('Sign-in failed: ' + e.message)); return; }
        currentUser = user;
        stopServerBrowserListener();
        try { await loadCloudProfile(); } catch (e) { notify('Could not load your profile: ' + e.message); }
        refreshAccountBar();
        document.getElementById('player-name').value = profile.playerName || 'Explorer';
        renderAccountWorldsList();
        if (document.getElementById('ui-layer').style.display === 'none') showMenuSection('main-menu');
    });
} else {
    document.addEventListener('DOMContentLoaded', () => { refreshAccountBar(); showMenuSection('main-menu'); });
}

window.addEventListener('beforeunload', () => {
    if (isHost) cleanupHost();
    else if (isMultiplayer) cleanupClient();
});
