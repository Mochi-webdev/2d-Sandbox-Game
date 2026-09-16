
function closeCraftingModal() {
    const modal = document.getElementById('crafting-modal');
    const backdrop = document.getElementById('crafting-backdrop');
    for (let i = 0; i < 9; i++) {
        if (craftingGrid[i].type !== BLOCKS.AIR && craftingGrid[i].count > 0) {
            addItemToInventory(craftingGrid[i].type, craftingGrid[i].count);
            craftingGrid[i] = emptySlot();
        }
    }
    checkCraftingRecipe();
    currentCraftingStation = null;
    modal.style.display = 'none';
    backdrop.style.display = 'none';
}

function openCraftingUI(station) {
    // station: null (basic), 'bench', or 'furnace'
    currentCraftingStation = station || null;
    const modal = document.getElementById('crafting-modal');
    const backdrop = document.getElementById('crafting-backdrop');
    const titleEl = document.getElementById('crafting-title');
    if (titleEl) {
        if (currentCraftingStation === 'bench') titleEl.innerText = 'CRAFTING BENCH';
        else if (currentCraftingStation === 'furnace') titleEl.innerText = 'FURNACE';
        else titleEl.innerText = 'BASIC CRAFTING';
    }
    modal.style.display = 'flex';
    backdrop.style.display = 'block';
    renderCraftingGrid();
    renderRecipeSideBook();
}

function openBasicCrafting() {
    openCraftingUI(null);
}

function toggleCraftingModal(forceClose) {
    const modal = document.getElementById('crafting-modal');
    const isVis = modal.style.display === 'flex';
    if (forceClose || isVis) {
        closeCraftingModal();
    } else {
        openBasicCrafting();
    }
}

function renderCraftingGrid() {
    const gridEl = document.getElementById('crafting-grid');
    gridEl.innerHTML = '';
    craftingGrid.forEach((slot, idx) => {
        const el = document.createElement('div');
        el.className = 'slot';
        renderSlotInElement(el, slot);
        el.onclick = () => { handleSlotClick(craftingGrid, idx); renderCraftingGrid(); };
        gridEl.appendChild(el);
    });
    const resEl = document.getElementById('crafting-result');
    renderSlotInElement(resEl, craftResult);
}

function renderRecipeSideBook() {
    const sideEl = document.getElementById('recipe-book-list');
    sideEl.innerHTML = '';
    RECIPES.forEach(r => {
        // Only show recipes that match the current station (or basic when station is null)
        const matchesStation = (currentCraftingStation === null && !r.station) ||
                               (currentCraftingStation === r.station);
        if (!matchesStation) return;

        const card = document.createElement('div');
        card.className = 'recipe-card';
        const iconCvs = generateItemIcon(r.result.type);
        const iconImg = document.createElement('img');
        iconImg.src = iconCvs.toDataURL();
        iconImg.style.width = '24px'; iconImg.style.height = '24px';
        card.appendChild(iconImg);
        const info = document.createElement('div');
        let stationNote = '';
        if (r.station === 'bench') stationNote = '<br><span style="color:#2ecc71;font-size:6px;">Crafting Bench</span>';
        if (r.station === 'furnace') stationNote = '<br><span style="color:#2ecc71;font-size:6px;">Furnace</span>';
        info.innerHTML = `<b style="color:var(--pixel-gold-bright);">${r.name}</b><br>Yield: ${r.result.count}x${stationNote}`;
        card.appendChild(info);
        card.onclick = () => fillCraftingGridFromRecipe(r);
        sideEl.appendChild(card);
    });
    if (sideEl.children.length === 0) {
        sideEl.innerHTML = '<div style="font-size:7px;color:#888;padding:8px;">No recipes for this station.</div>';
    }
}

function fillCraftingGridFromRecipe(recipe) {
    // Recipe must match the open station
    if ((recipe.station || null) !== (currentCraftingStation || null)) {
        notify(recipe.station === 'bench' ? "Open a Crafting Bench to use this recipe!" :
               recipe.station === 'furnace' ? "Open a Furnace to use this recipe!" :
               "This is a basic hand-craft recipe only.");
        return;
    }
    for (let i = 0; i < 9; i++) {
        if (craftingGrid[i].type !== BLOCKS.AIR) { addItemToInventory(craftingGrid[i].type, craftingGrid[i].count); craftingGrid[i] = emptySlot(); }
    }
    let missing = false;
    const allSlots = hotbar.concat(inventoryStorage);
    recipe.grid.forEach((reqBlock, idx) => {
        if (reqBlock !== null) {
            let found = false;
            for (let slot of allSlots) {
                if (slot.type === reqBlock && slot.count > 0) {
                    slot.count--;
                    if (slot.count <= 0) { slot.type = BLOCKS.AIR; slot.durability = 0; }
                    craftingGrid[idx] = { type: reqBlock, count: 1, durability: BLOCK_PROPS[reqBlock].maxDurability || 0 };
                    found = true; break;
                }
            }
            if (!found) missing = true;
        }
    });
    if (missing) notify("Missing ingredients!");
    checkCraftingRecipe();
    renderCraftingGrid();
    updateUI();
}

function handleSlotClick(container, index) {
    let slot = container[index];
    if (!heldItem || heldItem.count === 0 || heldItem.type === BLOCKS.AIR) {
        if (slot.type !== BLOCKS.AIR && slot.count > 0) { heldItem = { ...slot }; container[index] = emptySlot(); }
    } else {
        const prop = BLOCK_PROPS[heldItem.type];
        if (slot.type === BLOCKS.AIR) { container[index] = { ...heldItem }; heldItem = null; }
        else if (slot.type === heldItem.type && prop.itemType !== ITEM_TYPES.TOOL && prop.itemType !== ITEM_TYPES.ARMOR) { slot.count += heldItem.count; heldItem = null; }
        else { let temp = { ...slot }; container[index] = { ...heldItem }; heldItem = temp; }
    }
    checkCraftingRecipe();
    updateUI();
}

function handleEquipClick(slotKey) {
    const slot = equipment[slotKey];
    if (!heldItem || heldItem.count === 0 || heldItem.type === BLOCKS.AIR) {
        if (slot.type !== BLOCKS.AIR) { heldItem = { ...slot }; equipment[slotKey] = emptySlot(); }
    } else {
        const prop = BLOCK_PROPS[heldItem.type];
        if (prop && prop.itemType === ITEM_TYPES.ARMOR && prop.slot === slotKey) {
            const temp = { ...slot };
            equipment[slotKey] = { ...heldItem, count: 1 };
            heldItem = (temp.type !== BLOCKS.AIR) ? temp : null;
        } else {
            notify("That doesn't fit there!");
        }
    }
    renderInventoryModal();
    updateUI();
}

function checkCraftingRecipe() {
    craftResult = emptySlot();
    for (let recipe of RECIPES) {
        // Must match the currently open station
        if ((recipe.station || null) !== (currentCraftingStation || null)) continue;

        let matches = true;
        for (let i = 0; i < 9; i++) {
            let targetType = recipe.grid[i];
            let currentType = craftingGrid[i].type;
            if (targetType === null && currentType !== BLOCKS.AIR) matches = false;
            if (targetType !== null && currentType !== targetType) matches = false;
        }
        if (matches) {
            craftResult = { type: recipe.result.type, count: recipe.result.count, durability: recipe.result.durability || 0 };
            break;
        }
    }
}

function claimCraftResult() {
    if (craftResult.type === BLOCKS.AIR || craftResult.count <= 0) return;
    if (!heldItem || heldItem.count === 0 || heldItem.type === BLOCKS.AIR) { heldItem = { ...craftResult }; }
    else if (heldItem.type === craftResult.type) { heldItem.count += craftResult.count; }
    else { return notify("Clear hand first!"); }

    for (let i = 0; i < 9; i++) {
        if (craftingGrid[i].type !== BLOCKS.AIR) {
            craftingGrid[i].count--;
            if (craftingGrid[i].count <= 0) craftingGrid[i] = emptySlot();
        }
    }
    checkCraftingRecipe();
    renderCraftingGrid();
    updateUI();
}

function renderSlotInElement(el, slot) {
    el.innerHTML = '';
    if (!slot || slot.type === BLOCKS.AIR || slot.count <= 0) return;
    const prop = BLOCK_PROPS[slot.type];
    const cvs = generateItemIcon(slot.type);
    const img = document.createElement('img');
    img.src = cvs.toDataURL();
    img.className = 'item-icon-canvas';
    img.title = (prop.name || '') + (slot.count > 1 ? ' x' + slot.count : '');
    el.appendChild(img);
    el.title = img.title;

    if (prop.itemType !== ITEM_TYPES.TOOL && prop.itemType !== ITEM_TYPES.ARMOR && slot.count > 1) {
        const countEl = document.createElement('div');
        countEl.className = 'slot-count';
        countEl.innerText = slot.count;
        el.appendChild(countEl);
    }

    if (prop.itemType === ITEM_TYPES.TOOL && slot.durability > 0) {
        const durBar = document.createElement('div');
        durBar.className = 'durability-bar';
        const fill = document.createElement('div');
        fill.className = 'durability-fill';
        const maxDur = prop.maxDurability || 1;
        const ratio = slot.durability / maxDur;
        fill.style.width = Math.floor(ratio * 100) + '%';
        fill.style.background = ratio > 0.4 ? '#2ecc71' : (ratio > 0.15 ? '#f39c12' : '#e74c3c');
        durBar.appendChild(fill);
        el.appendChild(durBar);
    }
}

function updateUI() {
    const invEl = document.getElementById('inventory');
    if (!invEl) return;

    invEl.innerHTML = '';
    hotbar.forEach((slot, idx) => {
        const el = document.createElement('div');
        el.className = 'slot' + (idx === selectedSlot ? ' selected' : '');
        const keyEl = document.createElement('div');
        keyEl.className = 'slot-key';
        keyEl.innerText = idx + 1;
        el.appendChild(keyEl);

        renderSlotInElement(el, slot);

        el.onclick = () => {
            const invOpen = document.getElementById('inventory-modal')?.style.display === 'flex';
            if (invOpen) { 
                handleSlotClick(hotbar, idx); 
                renderInventoryModal(); 
            } else { 
                selectedSlot = idx; 
            }
            updateUI();
        };
        invEl.appendChild(el);
    });
}

function renderInventoryModal() {
    const storeEl = document.getElementById('storage-grid');
    if (!storeEl) return;

    storeEl.innerHTML = '';
    inventoryStorage.forEach((slot, idx) => {
        const el = document.createElement('div');
        el.className = 'slot';
        renderSlotInElement(el, slot);
        el.onclick = () => { 
            handleSlotClick(inventoryStorage, idx); 
            renderInventoryModal(); 
            updateUI(); 
        };
        storeEl.appendChild(el);
    });

    ['head', 'chest', 'legs', 'feet'].forEach(key => {
        const el = document.getElementById('equip-slot-' + key);
        if (el) {
            renderSlotInElement(el, equipment[key]);
            el.onclick = () => handleEquipClick(key);
        }
    });

    const defenseEl = document.getElementById('defense-display');
    if (defenseEl) defenseEl.innerText = 'Defense: ' + getTotalDefense() + '%';
}

function toggleInventoryModal(forceClose) {
    const modal = document.getElementById('inventory-modal');
    const backdrop = document.getElementById('inventory-backdrop');
    if (!modal) return;
    const isVis = modal.style.display === 'flex';
    if (forceClose || isVis) {
        modal.style.display = 'none';
        backdrop.style.display = 'none';
    } else {
        modal.style.display = 'flex';
        backdrop.style.display = 'block';
        renderInventoryModal();
    }
}

function openChestModal(col, row) {
    const key = chestKey(col, row);
    if (!chestData[key]) chestData[key] = emptyChestSlots();
    openChestKey = key;
    renderChestGrid();
    document.getElementById('chest-modal').style.display = 'flex';
    document.getElementById('chest-backdrop').style.display = 'block';
    notify('Opened Chest (Shift+click to mine)');
}

function closeChestModal() {
    openChestKey = null;
    const modal = document.getElementById('chest-modal');
    const backdrop = document.getElementById('chest-backdrop');
    if (modal) modal.style.display = 'none';
    if (backdrop) backdrop.style.display = 'none';
}

function renderChestGrid() {
    const gridEl = document.getElementById('chest-grid');
    if (!gridEl || !openChestKey) return;
    const slots = chestData[openChestKey] || emptyChestSlots();
    chestData[openChestKey] = slots;
    gridEl.innerHTML = '';
    slots.forEach((slot, idx) => {
        const el = document.createElement('div');
        el.className = 'slot';
        renderSlotInElement(el, slot);
        el.onclick = () => {
            handleSlotClick(slots, idx);
            renderChestGrid();
            updateUI();
        };
        gridEl.appendChild(el);
    });
}

function addItemToInventory(type, count) {
    const prop = BLOCK_PROPS[type];
    const stackable = prop.itemType !== ITEM_TYPES.TOOL && prop.itemType !== ITEM_TYPES.ARMOR;
    const allSlots = hotbar.concat(inventoryStorage);

    for (let slot of allSlots) {
        if (slot.type === type && stackable) { slot.count += count; updateUI(); return; }
    }
    for (let slot of allSlots) {
        if (slot.type === BLOCKS.AIR || slot.count === 0) {
            slot.type = type; slot.count = count; slot.durability = prop.maxDurability || 0;
            updateUI(); return;
        }
    }
    notify("Inventory Full!");
}

function getWorldSnapshot() {
    return {
        world: Array.from(world), wallWorld: Array.from(wallWorld), player: player,
        hotbar, inventoryStorage, equipment, gameMode, worldSeed,
        chestData: chestData
    };
}

function applyWorldSnapshot(data) {
    world = new Uint8Array(data.world);
    wallWorld = new Uint8Array(data.wallWorld);
    blockDamageMap.fill(0);
    if (data.hotbar) hotbar = data.hotbar;
    if (data.inventoryStorage) inventoryStorage = data.inventoryStorage;
    if (data.equipment) equipment = data.equipment;
    if (data.player) player = { ...player, ...data.player, health: (data.player.health != null ? data.player.health : 100) };
    if (data.gameMode) { gameMode = data.gameMode; document.getElementById('game-mode').value = gameMode; }
    chestData = data.chestData || {};
    openChestKey = null;
}
